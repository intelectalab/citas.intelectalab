<?php

namespace App\Support\Billing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

class NubefactInvoiceProvider implements InvoiceProvider
{
    public const TIMEOUT = 25;

    public const ALLOWED_HOSTS = ['nubefact.com', 'pse.pe'];

    private const TYPES = ['factura' => 1, 'boleta' => 2, 'nota_credito' => 3];

    private const CURRENCIES = ['PEN' => 1, 'USD' => 2, 'EUR' => 3];

    private const CUSTOMER_DOCS = ['RUC' => '6', 'L.E / DNI' => '1', 'DNI' => '1', 'CARNET DE EXTRANJERIA' => '4', 'PASAPORTE' => '7'];

    public const NOTE_TYPES = ['anulacion' => 1, 'devolucion_total' => 6, 'disminucion' => 9];

    private string $route;

    private string $token;

    public function __construct(?object $integration = null, ?string $route = null, ?string $token = null)
    {
        $saved = $integration && $integration->credentials ? (json_decode(Crypt::decryptString($integration->credentials), true) ?: []) : [];
        $this->route = trim((string) ($route ?? $saved['route'] ?? ''));
        $this->token = trim((string) ($token ?? $saved['token'] ?? ''));
    }

    public static function validRoute(string $route): bool
    {
        $parts = parse_url(trim($route));

        if (($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])) {
            return false;
        }

        $host = strtolower($parts['host']);

        foreach (self::ALLOWED_HOSTS as $allowed) {
            if ($host === $allowed || str_ends_with($host, '.' . $allowed)) {
                return true;
            }
        }

        return false;
    }

    public function key(): string
    {
        return 'nubefact';
    }

    public function real(): bool
    {
        return true;
    }

    private function call(array $payload): array
    {
        if (!self::validRoute($this->route) || $this->token === '') {
            return ['ok' => false, 'code' => 11, 'error' => 'Falta la ruta o el token de Nubefact en Configuración → Integraciones.'];
        }

        $response = Http::withHeaders(['Authorization' => 'Token token="' . $this->token . '"'])
            ->acceptJson()
            ->asJson()
            ->connectTimeout(8)
            ->timeout(self::TIMEOUT)
            ->post($this->route, $payload);

        $body = $response->json();

        if ($response->serverError() || !is_array($body)) {
            throw new ConnectionException('Nubefact respondió ' . $response->status());
        }

        if (isset($body['errors'])) {
            return ['ok' => false, 'code' => (int) ($body['codigo'] ?? 0), 'error' => is_array($body['errors']) ? implode(' ', $body['errors']) : (string) $body['errors']];
        }

        return ['ok' => true] + $body;
    }

    private static function explain(int $code, string $error): string
    {
        return match ($code) {
            10    => 'Nubefact no reconoce el token. Revísalo en Configuración → Integraciones.',
            11    => 'La ruta de Nubefact no es correcta. Revísala en Configuración → Integraciones.',
            50, 51 => 'Nubefact dice que la cuenta está suspendida. Revisa tu cuenta de Nubefact.',
            default => 'Nubefact no lo aceptó: ' . $error,
        };
    }

    private function answer(array $body): array
    {
        $accepted = filter_var($body['aceptada_por_sunat'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $code = trim((string) ($body['sunat_responsecode'] ?? ''));
        $rejected = !$accepted && $code !== '' && $code !== '0' && (int) $code >= 2000;
        $description = trim((string) ($body['sunat_description'] ?? '')) ?: trim((string) ($body['sunat_soap_error'] ?? ''));

        return [
            'status'      => $accepted ? 'accepted' : ($rejected ? 'rejected' : 'pending'),
            'received'    => true,
            'message'     => $accepted
                ? ($description ?: 'SUNAT aceptó el comprobante.')
                : ($rejected ? 'SUNAT lo rechazó: ' . $description : 'Nubefact lo recibió. SUNAT aún no lo confirma' . ($description ? ': ' . $description : '.')),
            'external_id' => isset($body['enlace']) ? mb_substr((string) $body['enlace'], 0, 190) : (isset($body['serie'], $body['numero']) ? $body['serie'] . '-' . $body['numero'] : null),
            'simulated'   => false,
            'pdf_url'     => $body['enlace_del_pdf'] ?? null,
            'xml_url'     => $body['enlace_del_xml'] ?? null,
            'cdr_url'     => $body['enlace_del_cdr'] ?? null,
        ];
    }

    private static function failed(array $result): array
    {
        return ['status' => 'pending', 'received' => false, 'message' => self::explain($result['code'], $result['error']), 'external_id' => null, 'simulated' => false, 'error_code' => $result['code']];
    }

    public function send(array $document): array
    {
        $result = $this->call($this->payload($document));

        if (!$result['ok'] && $result['code'] === 23) {
            return $this->status($document);
        }

        return $result['ok'] ? $this->answer($result) : self::failed($result);
    }

    public function status(array $document): array
    {
        $result = $this->call([
            'operacion'           => 'consultar_comprobante',
            'tipo_de_comprobante' => self::TYPES[$document['doc_type']] ?? 2,
            'serie'               => $document['serie'],
            'numero'              => (int) $document['number'],
        ]);

        if (!$result['ok'] && $result['code'] === 24) {
            return ['status' => 'pending', 'received' => false, 'message' => 'Nubefact todavía no tiene este comprobante.', 'external_id' => null, 'simulated' => false, 'not_found' => true];
        }

        return $result['ok'] ? $this->answer($result) : self::failed($result);
    }

    private function voidAnswer(array $result): array
    {
        if (!$result['ok']) {
            return ['status' => 'error', 'message' => self::explain($result['code'], $result['error']), 'ticket' => null];
        }

        $accepted = filter_var($result['aceptada_por_sunat'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $code = trim((string) ($result['sunat_responsecode'] ?? ''));
        $rejected = !$accepted && $code !== '' && $code !== '0' && (int) $code >= 2000;
        $description = trim((string) ($result['sunat_description'] ?? '')) ?: trim((string) ($result['sunat_soap_error'] ?? ''));

        return [
            'status'  => $accepted ? 'accepted' : ($rejected ? 'rejected' : 'pending'),
            'message' => $accepted ? 'SUNAT aceptó la baja.' : ($rejected ? 'SUNAT rechazó la baja: ' . $description : 'Baja enviada. SUNAT aún no la confirma.'),
            'ticket'  => isset($result['sunat_ticket_numero']) ? mb_substr((string) $result['sunat_ticket_numero'], 0, 190) : null,
        ];
    }

    public function void(array $document, string $reason): array
    {
        return $this->voidAnswer($this->call([
            'operacion'           => 'generar_anulacion',
            'tipo_de_comprobante' => self::TYPES[$document['doc_type']] ?? 2,
            'serie'               => $document['serie'],
            'numero'              => (int) $document['number'],
            'motivo'              => mb_substr(mb_strtoupper($reason), 0, 100),
            'codigo_unico'        => '',
        ]));
    }

    public function voidStatus(array $document): array
    {
        return $this->voidAnswer($this->call([
            'operacion'           => 'consultar_anulacion',
            'tipo_de_comprobante' => self::TYPES[$document['doc_type']] ?? 2,
            'serie'               => $document['serie'],
            'numero'              => (int) $document['number'],
        ]));
    }

    public function test(array $series): array
    {
        try {
            $result = $this->call([
                'operacion'           => 'consultar_comprobante',
                'tipo_de_comprobante' => 2,
                'serie'               => $series['boleta'] ?? 'B001',
                'numero'              => 99999999,
            ]);
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Nubefact no respondió. Revisa la ruta o inténtalo en unos minutos.'];
        }

        if ($result['ok'] || $result['code'] === 24) {
            return ['ok' => true, 'message' => 'Conexión correcta: Nubefact reconoció la ruta y el token de tu empresa.'];
        }

        return ['ok' => false, 'message' => self::explain($result['code'], $result['error'])];
    }

    public static function money(float $value): float
    {
        return round($value, 2);
    }

    public function payload(array $document): array
    {
        $type = $document['doc_type'];
        $currency = $document['currency'] ?? 'PEN';

        if (!isset(self::CURRENCIES[$currency])) {
            throw new \DomainException("Nubefact no emite comprobantes en {$currency}.");
        }

        $docType = self::CUSTOMER_DOCS[mb_strtoupper((string) ($document['customer_doc_type'] ?? ''))] ?? '-';
        $docNumber = preg_replace('/\s+/', '', (string) ($document['customer_doc_number'] ?? ''));

        if ($docType === '1' && !preg_match('/^\d{8}$/', $docNumber)) {
            $docType = '-';
        }

        if ($docType === '-' || $docNumber === '') {
            $docType = '-';
            $docNumber = '00000000';
        }

        $rate = (float) ($document['tax_rate'] ?? 18);
        $exempt = (float) $document['tax'] <= 0;

        $items = array_map(fn (array $line) => [
            'unidad_de_medida'        => $line['unit'],
            'codigo'                  => mb_substr((string) $line['code'], 0, 30),
            'descripcion'             => mb_substr((string) $line['description'], 0, 250),
            'cantidad'                => $line['quantity'],
            'valor_unitario'          => round($line['taxable'] / $line['quantity'], 10),
            'precio_unitario'         => round($line['total'] / $line['quantity'], 10),
            'descuento'               => '',
            'subtotal'                => self::money($line['taxable']),
            'tipo_de_igv'             => $exempt ? 8 : 1,
            'igv'                     => self::money($line['tax']),
            'total'                   => self::money($line['total']),
            'anticipo_regularizacion' => false,
            'anticipo_documento_serie' => '',
            'anticipo_documento_numero' => '',
        ], $document['lines']);

        $payload = [
            'operacion'                         => 'generar_comprobante',
            'tipo_de_comprobante'               => self::TYPES[$type],
            'serie'                             => $document['serie'],
            'numero'                            => (int) $document['number'],
            'sunat_transaction'                 => 1,
            'cliente_tipo_de_documento'         => $docType,
            'cliente_numero_de_documento'       => $docNumber,
            'cliente_denominacion'              => mb_substr(trim((string) ($document['customer_name'] ?? '')) ?: 'CLIENTES VARIOS', 0, 100),
            'cliente_direccion'                 => mb_substr((string) ($document['customer_address'] ?? ''), 0, 100),
            'cliente_email'                     => (string) ($document['customer_email'] ?? ''),
            'cliente_email_1'                   => '',
            'cliente_email_2'                   => '',
            'fecha_de_emision'                  => Carbon::parse($document['issued_at'])->timezone('America/Lima')->format('d-m-Y'),
            'fecha_de_vencimiento'              => '',
            'moneda'                            => self::CURRENCIES[$currency],
            'tipo_de_cambio'                    => '',
            'porcentaje_de_igv'                 => number_format($exempt ? 18 : $rate, 2, '.', ''),
            'descuento_global'                  => '',
            'total_descuento'                   => '',
            'total_anticipo'                    => '',
            'total_gravada'                     => $exempt ? '' : self::money((float) $document['taxable']),
            'total_inafecta'                    => '',
            'total_exonerada'                   => $exempt ? self::money((float) $document['total']) : '',
            'total_igv'                         => self::money((float) $document['tax']),
            'total_gratuita'                    => '',
            'total_otros_cargos'                => '',
            'total'                             => self::money((float) $document['total']),
            'percepcion_tipo'                   => '',
            'percepcion_base_imponible'         => '',
            'total_percepcion'                  => '',
            'total_incluido_percepcion'         => '',
            'detraccion'                        => false,
            'observaciones'                     => '',
            'documento_que_se_modifica_tipo'    => '',
            'documento_que_se_modifica_serie'   => '',
            'documento_que_se_modifica_numero'  => '',
            'tipo_de_nota_de_credito'           => '',
            'tipo_de_nota_de_debito'            => '',
            'enviar_automaticamente_a_la_sunat' => true,
            'enviar_automaticamente_al_cliente' => false,
            'codigo_unico'                      => '',
            'condiciones_de_pago'               => '',
            'medio_de_pago'                     => '',
            'placa_vehiculo'                    => '',
            'orden_compra_servicio'             => '',
            'tabla_personalizada_codigo'        => '',
            'formato_de_pdf'                    => '',
            'items'                             => $items,
        ];

        if ($type === 'nota_credito') {
            $reference = $document['reference'];
            $payload['documento_que_se_modifica_tipo'] = self::TYPES[$reference['doc_type']] ?? 2;
            $payload['documento_que_se_modifica_serie'] = $reference['serie'];
            $payload['documento_que_se_modifica_numero'] = (int) $reference['number'];
            $payload['tipo_de_nota_de_credito'] = self::NOTE_TYPES[$document['note_type'] ?? 'disminucion'] ?? 9;
            $payload['observaciones'] = mb_substr((string) ($document['reason'] ?? ''), 0, 250);
        }

        return $payload;
    }
}
