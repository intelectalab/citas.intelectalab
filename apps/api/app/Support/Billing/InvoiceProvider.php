<?php

namespace App\Support\Billing;

/**
 * Proveedor de facturación electrónica (el que envía boletas y facturas a
 * SUNAT). Ventas no sabe cuál es: solo le entrega el comprobante y recibe la
 * respuesta. Conectar uno real es agregar una clase que cumpla este contrato y
 * registrarla en Invoicing::PROVIDERS.
 */
interface InvoiceProvider
{
    public function key(): string;

    /**
     * @param array<string, mixed> $document Emisor, cliente, serie, número, ítems y totales.
     * @return array{status: string, message: ?string, external_id: ?string, simulated: bool}
     *         status: accepted, rejected o pending (el proveedor aún no responde).
     */
    public function send(array $document): array;
}
