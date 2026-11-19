<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const RUTA = 'https://api.nubefact.com/api/v1/ruta-de-prueba-1234';

function aceptado(array $data): array
{
    return [
        'tipo_de_comprobante' => $data['tipo_de_comprobante'] ?? 2, 'serie' => $data['serie'] ?? 'B001', 'numero' => $data['numero'] ?? 1,
        'enlace' => 'https://www.nubefact.com/cpe/abc-' . ($data['serie'] ?? '') . '-' . ($data['numero'] ?? ''),
        'aceptada_por_sunat' => true, 'sunat_description' => 'La Boleta numero B001-1, ha sido aceptada', 'sunat_note' => null,
        'sunat_responsecode' => '0', 'sunat_soap_error' => '',
        'enlace_del_pdf' => 'https://www.nubefact.com/cpe/abc.pdf', 'enlace_del_xml' => 'https://www.nubefact.com/cpe/abc.xml', 'enlace_del_cdr' => 'https://www.nubefact.com/cpe/abc.cdr',
    ];
}

beforeEach(function () {
    $this->co = $this->company();
    $this->openCash($this->co);
    $this->servicio = $this->servicePriced($this->co->tenant, 118, 'Limpieza');
    $this->responder = fn (array $data) => Http::response(aceptado($data));
    Http::fake(function (Request $request) {
        $result = ($this->responder)($request->data());

        return $result instanceof \Throwable ? throw $result : $result;
    });

    $this->conectar = function () {
        return $this->as($this->co->owner)->putJson('/api/integrations/invoicing', ['provider' => 'nubefact', 'route' => RUTA, 'token' => 'token-secreto-9876', 'mode' => 'sandbox']);
    };

    $this->ventaPagada = function (string $tipo = 'boleta', array $extra = []) {
        $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]]);
        $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => 118, 'doc_type' => $tipo] + $extra)->assertOk();

        return [$venta['id'], DB::table('invoices')->where('sale_id', $venta['id'])->where('doc_type', $tipo)->first()];
    };

    $this->operaciones = fn () => collect(Http::recorded())->map(fn ($pair) => $pair[0]->data()['operacion'] ?? null)->values()->all();
});

it('sin proveedor conectado sigue simulando y no llama a nadie', function () {
    [, $boleta] = ($this->ventaPagada)();

    expect($boleta->provider)->toBe('simulated')
        ->and($boleta->sunat_status)->toBe('accepted')
        ->and($boleta->serie)->toBe('B001');
    Http::assertNothingSent();
});

it('guarda la ruta y el token de Nubefact cifrados y rechaza una ruta que no es de Nubefact', function () {
    $this->as($this->co->owner)->putJson('/api/integrations/invoicing', ['provider' => 'nubefact', 'route' => 'https://otro-sitio.com/api', 'token' => 'x'])
        ->assertStatus(422)->assertJsonValidationErrors('route');

    ($this->conectar)()->assertOk()->assertJsonPath('provider', 'nubefact')->assertJsonPath('active', 'nubefact');

    $fila = DB::table('tenant_integrations')->where('tenant_id', $this->co->tenant->id)->where('kind', 'invoicing')->first();
    expect($fila->credentials)->not->toContain('token-secreto')
        ->and(json_decode(Crypt::decryptString($fila->credentials), true))->toBe(['route' => RUTA, 'token' => 'token-secreto-9876']);

    $this->as($this->co->owner)->getJson('/api/integrations/invoicing')->assertOk()
        ->assertJsonPath('saved.token', '••••••••9876');
});

it('probar la conexión dice con palabras si el token está bien o mal', function () {
    ($this->conectar)()->assertOk();

    $this->responder = fn () => Http::response(['errors' => 'El documento indicado no existe', 'codigo' => 24], 400);
    $this->as($this->co->owner)->postJson('/api/integrations/invoicing/test')->assertOk()->assertJsonPath('ok', true);

    $this->responder = fn () => Http::response(['errors' => 'Token incorrecto', 'codigo' => 10], 401);
    $respuesta = $this->as($this->co->owner)->postJson('/api/integrations/invoicing/test')->assertOk()->assertJsonPath('ok', false);
    expect($respuesta->json('message'))->toContain('token');

    Http::assertSent(fn (Request $r) => $r->url() === RUTA && $r->header('Authorization')[0] === 'Token token="token-secreto-9876"');
});

it('una boleta pagada se envía a Nubefact con IGV y totales cuadrados y guarda sus enlaces', function () {
    ($this->conectar)()->assertOk();
    [, $boleta] = ($this->ventaPagada)();

    expect($boleta->provider)->toBe('nubefact')
        ->and($boleta->sunat_status)->toBe('accepted')
        ->and((bool) $boleta->simulated)->toBeFalse()
        ->and($boleta->pdf_url)->toBe('https://www.nubefact.com/cpe/abc.pdf')
        ->and($boleta->cdr_url)->toBe('https://www.nubefact.com/cpe/abc.cdr');

    $envio = collect(Http::recorded())->first(fn ($pair) => ($pair[0]->data()['operacion'] ?? '') === 'generar_comprobante')[0]->data();
    expect($envio['tipo_de_comprobante'])->toBe(2)
        ->and($envio['serie'])->toBe('B001')
        ->and($envio['numero'])->toBe(1)
        ->and($envio['moneda'])->toBe(1)
        ->and($envio['cliente_tipo_de_documento'])->toBe('-')
        ->and((float) $envio['total'])->toBe(118.0)
        ->and((float) $envio['total_igv'])->toBe(18.0)
        ->and((float) $envio['total_gravada'])->toBe(100.0)
        ->and(round(collect($envio['items'])->sum('total'), 2))->toBe(118.0)
        ->and(round(collect($envio['items'])->sum('igv'), 2))->toBe(18.0)
        ->and($envio['items'][0]['tipo_de_igv'])->toBe(1)
        ->and($envio['items'][0]['unidad_de_medida'])->toBe('ZZ')
        ->and($envio['fecha_de_emision'])->toBe(now('America/Lima')->format('d-m-Y'));

    $fila = collect($this->as($this->co->owner)->getJson('/api/invoices')->json('invoices'))->firstWhere('id', $boleta->id);
    expect($fila['state_label'])->toBe('Aceptado por SUNAT')->and($fila['pdf_url'])->not->toBeNull();
});

it('una factura va con tipo 1 y el RUC del cliente', function () {
    ($this->conectar)()->assertOk();
    [, $factura] = ($this->ventaPagada)('factura', ['ruc' => '20123456789', 'business_name' => 'Empresa Cliente SAC']);

    $envio = collect(Http::recorded())->first(fn ($pair) => ($pair[0]->data()['operacion'] ?? '') === 'generar_comprobante')[0]->data();
    expect($factura->serie)->toBe('F001')
        ->and($envio['tipo_de_comprobante'])->toBe(1)
        ->and($envio['cliente_tipo_de_documento'])->toBe('6')
        ->and($envio['cliente_numero_de_documento'])->toBe('20123456789')
        ->and($envio['cliente_denominacion'])->toBe('Empresa Cliente SAC');
});

it('si Nubefact no responde queda pendiente de envío y la tarea programada lo reintenta', function () {
    ($this->conectar)()->assertOk();
    $this->responder = fn () => new ConnectionException('sin conexión');
    [, $boleta] = ($this->ventaPagada)();

    expect($boleta->sunat_status)->toBe('pending')
        ->and($boleta->external_id)->toBeNull()
        ->and((int) $boleta->send_attempts)->toBe(1)
        ->and($boleta->next_attempt_at)->not->toBeNull();

    $fila = collect($this->as($this->co->owner)->getJson('/api/invoices')->json('invoices'))->firstWhere('id', $boleta->id);
    expect($fila['state_label'])->toBe('Pendiente de envío')->and($fila['can_resend'])->toBeTrue();

    $this->artisan('invoices:retry')->assertSuccessful();
    expect(DB::table('invoices')->where('id', $boleta->id)->value('send_attempts'))->toBe(1);

    $this->responder = fn (array $data) => Http::response(aceptado($data));
    $this->travel(10)->minutes();
    $this->artisan('invoices:retry')->assertSuccessful();

    $despues = DB::table('invoices')->where('id', $boleta->id)->first();
    expect($despues->sunat_status)->toBe('accepted')
        ->and($despues->next_attempt_at)->toBeNull()
        ->and($despues->pdf_url)->not->toBeNull();
});

it('si Nubefact lo recibió pero SUNAT no confirma, el reintento consulta en vez de volver a enviar', function () {
    ($this->conectar)()->assertOk();
    $this->responder = function (array $data) {
        $base = aceptado($data);
        $base['aceptada_por_sunat'] = false;
        $base['sunat_responsecode'] = '';
        $base['sunat_description'] = '';

        return Http::response($base);
    };
    [, $boleta] = ($this->ventaPagada)();

    expect($boleta->sunat_status)->toBe('pending')->and($boleta->external_id)->not->toBeNull();
    expect(collect($this->as($this->co->owner)->getJson('/api/invoices')->json('invoices'))->firstWhere('id', $boleta->id)['state_label'])->toBe('Esperando respuesta de SUNAT');

    $this->responder = fn (array $data) => Http::response(aceptado($data));
    $this->travel(10)->minutes();
    $this->artisan('invoices:retry')->assertSuccessful();

    expect(DB::table('invoices')->where('id', $boleta->id)->value('sunat_status'))->toBe('accepted')
        ->and(($this->operaciones)())->toBe(['generar_comprobante', 'consultar_comprobante']);
});

it('si SUNAT lo rechaza se ve rechazado con su motivo', function () {
    ($this->conectar)()->assertOk();
    $this->responder = function (array $data) {
        $base = aceptado($data);
        $base['aceptada_por_sunat'] = false;
        $base['sunat_responsecode'] = '2800';
        $base['sunat_description'] = 'El dato ingresado en el tipo de documento de identidad del receptor no esta permitido.';

        return Http::response($base);
    };
    [, $boleta] = ($this->ventaPagada)();

    expect($boleta->sunat_status)->toBe('rejected')
        ->and($boleta->sunat_message)->toContain('SUNAT lo rechazó')
        ->and($boleta->next_attempt_at)->toBeNull();
});

it('una devolución total con comprobante emite la nota de crédito por Nubefact', function () {
    ($this->conectar)()->assertOk();
    [$ventaId, $boleta] = ($this->ventaPagada)();

    $this->as($this->co->owner)->postJson("/api/sales/{$ventaId}/refunds", ['amount' => 118, 'method' => 'cash', 'reason' => 'El servicio no se realizó'])->assertSuccessful();

    $nota = DB::table('invoices')->where('sale_id', $ventaId)->where('doc_type', 'nota_credito')->first();
    expect($nota)->not->toBeNull()
        ->and($nota->serie)->toBe('BC01')
        ->and($nota->provider)->toBe('nubefact')
        ->and($nota->sunat_status)->toBe('accepted');

    $envio = collect(Http::recorded())->last(fn ($pair) => ($pair[0]->data()['operacion'] ?? '') === 'generar_comprobante')[0]->data();
    expect($envio['tipo_de_comprobante'])->toBe(3)
        ->and($envio['documento_que_se_modifica_tipo'])->toBe(2)
        ->and($envio['documento_que_se_modifica_serie'])->toBe('B001')
        ->and($envio['documento_que_se_modifica_numero'])->toBe($boleta->number)
        ->and($envio['tipo_de_nota_de_credito'])->toBe(6)
        ->and((float) $envio['total'])->toBe(118.0);
});

it('una devolución parcial va como disminución en el valor', function () {
    ($this->conectar)()->assertOk();
    [$ventaId] = ($this->ventaPagada)();

    $this->as($this->co->owner)->postJson("/api/sales/{$ventaId}/refunds", ['amount' => 59, 'method' => 'cash', 'reason' => 'Se cobró de más'])->assertSuccessful();

    $envio = collect(Http::recorded())->last(fn ($pair) => ($pair[0]->data()['operacion'] ?? '') === 'generar_comprobante')[0]->data();
    expect($envio['tipo_de_nota_de_credito'])->toBe(9)
        ->and((float) $envio['total'])->toBe(59.0)
        ->and((float) $envio['total_igv'])->toBe(9.0)
        ->and(count($envio['items']))->toBe(1);
});

it('anular dentro del plazo comunica la baja a SUNAT por Nubefact', function () {
    ($this->conectar)()->assertOk();
    [, $boleta] = ($this->ventaPagada)();
    $this->responder = function (array $data) {
        if ($data['operacion'] === 'generar_anulacion') {
            return Http::response(['numero' => 1, 'enlace' => 'https://www.nubefact.com/anulacion/1', 'sunat_ticket_numero' => '1234567890', 'aceptada_por_sunat' => false, 'sunat_description' => null, 'sunat_responsecode' => null]);
        }
        if ($data['operacion'] === 'consultar_anulacion') {
            return Http::response(['numero' => 1, 'sunat_ticket_numero' => '1234567890', 'aceptada_por_sunat' => true, 'sunat_description' => 'La Comunicacion de baja ha sido aceptada', 'sunat_responsecode' => '0']);
        }

        return Http::response(aceptado($data));
    };

    $fila = collect($this->as($this->co->owner)->getJson('/api/invoices')->json('invoices'))->firstWhere('id', $boleta->id);
    expect($fila['void_plan'])->toBe('baja');

    $this->as($this->co->owner)->postJson("/api/invoices/{$boleta->id}/void", ['reason' => 'Se emitió por error'])->assertOk()
        ->assertJsonPath('status', 'voided')
        ->assertJsonPath('void_method', 'baja')
        ->assertJsonPath('void_status', 'pending');

    $anulacion = collect(Http::recorded())->first(fn ($pair) => ($pair[0]->data()['operacion'] ?? '') === 'generar_anulacion')[0]->data();
    expect($anulacion['serie'])->toBe('B001')->and($anulacion['motivo'])->toBe('SE EMITIÓ POR ERROR');

    $this->travel(10)->minutes();
    $this->artisan('invoices:retry')->assertSuccessful();

    $despues = DB::table('invoices')->where('id', $boleta->id)->first();
    expect($despues->void_status)->toBe('accepted')->and($despues->void_ticket)->toBe('1234567890');
});

it('si la baja falla el comprobante sigue vigente', function () {
    ($this->conectar)()->assertOk();
    [, $boleta] = ($this->ventaPagada)();
    $this->responder = fn (array $data) => $data['operacion'] === 'generar_anulacion'
        ? Http::response(['errors' => 'El documento no puede ser anulado', 'codigo' => 21], 400)
        : Http::response(aceptado($data));

    $this->as($this->co->owner)->postJson("/api/invoices/{$boleta->id}/void", ['reason' => 'Se emitió por error'])->assertStatus(422);

    expect(DB::table('invoices')->where('id', $boleta->id)->value('status'))->toBe('issued');
});

it('pasado el plazo de la baja, anular emite una nota de crédito de anulación', function () {
    ($this->conectar)()->assertOk();
    [$ventaId, $boleta] = ($this->ventaPagada)();
    $this->travel(8)->days();

    $fila = collect($this->as($this->co->owner)->getJson('/api/invoices')->json('invoices'))->firstWhere('id', $boleta->id);
    expect($fila['void_plan'])->toBe('credit_note');

    $this->as($this->co->owner)->postJson("/api/invoices/{$boleta->id}/void", ['reason' => 'Datos mal escritos'])->assertOk()
        ->assertJsonPath('void_method', 'credit_note');

    $nota = DB::table('invoices')->where('sale_id', $ventaId)->where('doc_type', 'nota_credito')->first();
    expect($nota)->not->toBeNull()
        ->and($nota->note_type)->toBe('anulacion')
        ->and(DB::table('invoices')->where('id', $boleta->id)->value('void_note_id'))->toBe($nota->id)
        ->and(($this->operaciones)())->not->toContain('generar_anulacion');

    $envio = collect(Http::recorded())->last(fn ($pair) => ($pair[0]->data()['operacion'] ?? '') === 'generar_comprobante')[0]->data();
    expect($envio['tipo_de_nota_de_credito'])->toBe(1)->and((float) $envio['total'])->toBe(118.0);
});

it('anular un comprobante que nunca llegó a Nubefact solo lo anula en el sistema', function () {
    ($this->conectar)()->assertOk();
    $this->responder = fn () => new ConnectionException('sin conexión');
    [, $boleta] = ($this->ventaPagada)();

    $this->responder = fn () => Http::response(['errors' => 'El documento indicado no existe', 'codigo' => 24], 400);
    $this->as($this->co->owner)->postJson("/api/invoices/{$boleta->id}/void", ['reason' => 'Se emitió por error'])->assertOk()
        ->assertJsonPath('void_method', 'local');

    expect(($this->operaciones)())->not->toContain('generar_anulacion');

    $this->travel(30)->minutes();
    Http::fake();
    $this->artisan('invoices:retry')->assertSuccessful();
    expect(DB::table('invoices')->where('id', $boleta->id)->value('status'))->toBe('voided');
});

it('cada sede puede tener su serie y las demás usan la de la empresa', function () {
    $sede = $this->branch($this->co, 'Sede Norte');

    $this->as($this->co->owner)->putJson('/api/integrations/invoicing/series', ['branches' => [['id' => $sede->id, 'series' => ['boleta' => 'F002']]]])
        ->assertStatus(422);

    $this->as($this->co->owner)->putJson('/api/integrations/invoicing/series', [
        'series'   => ['boleta' => 'b005', 'factura' => 'F005', 'nota_boleta' => 'BC05', 'nota_factura' => 'FC05'],
        'branches' => [['id' => $sede->id, 'series' => ['boleta' => 'B002', 'factura' => '']]],
    ])->assertOk()->assertJsonPath('series.boleta', 'B005');

    expect(\App\Support\Invoicing::seriesFor($this->co->tenant->id, $sede->id, 'boleta'))->toBe('B002')
        ->and(\App\Support\Invoicing::seriesFor($this->co->tenant->id, $sede->id, 'factura'))->toBe('F005')
        ->and(\App\Support\Invoicing::seriesFor($this->co->tenant->id, $this->co->branch->id, 'boleta'))->toBe('B005');

    [, $boleta] = ($this->ventaPagada)();
    expect($boleta->serie)->toBe('B005')->and($boleta->number)->toBe(1);

    $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]]);
    DB::table('sales')->where('id', $venta['id'])->update(['branch_id' => $sede->id, 'status' => 'paid', 'paid' => 118, 'balance' => 0]);
    $otra = \App\Support\Invoicing::issue($this->co->tenant, DB::table('sales')->where('id', $venta['id'])->first(), ['doc_type' => 'boleta', 'ruc' => null, 'business_name' => null, 'address' => null], $this->co->owner);
    expect($otra->serie)->toBe('B002')->and($otra->number)->toBe(1);
});

it('recepción no puede cambiar la facturación electrónica', function () {
    $recepcion = $this->staff($this->co, 'reception', $this->co->branch);

    $this->as($recepcion)->putJson('/api/integrations/invoicing', ['provider' => 'nubefact', 'route' => RUTA, 'token' => 'x'])->assertForbidden();
});

it('desconectar Nubefact vuelve a la simulación sin tocar lo ya enviado', function () {
    ($this->conectar)()->assertOk();
    [, $boleta] = ($this->ventaPagada)();

    $this->as($this->co->owner)->putJson('/api/integrations/invoicing', ['provider' => 'simulated'])->assertOk()->assertJsonPath('active', 'simulated');

    expect(DB::table('invoices')->where('id', $boleta->id)->value('provider'))->toBe('nubefact');
    [, $nueva] = ($this->ventaPagada)();
    expect($nueva->provider)->toBe('simulated');
});
