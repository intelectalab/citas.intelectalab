<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->co = $this->company();
    $this->openCash($this->co);
    $this->servicio = $this->servicePriced($this->co->tenant, 118, 'Limpieza');
    $this->respuesta = [
        'aceptada_por_sunat' => true, 'sunat_description' => 'Aceptada', 'sunat_note' => null, 'sunat_responsecode' => '0', 'sunat_soap_error' => '',
        'enlace' => 'https://www.nubefact.com/cpe/obs-1',
        'enlace_del_pdf' => 'https://www.nubefact.com/cpe/obs.pdf', 'enlace_del_xml' => 'https://www.nubefact.com/cpe/obs.xml', 'enlace_del_cdr' => 'https://www.nubefact.com/cpe/obs.cdr',
    ];
    Http::fake(function (Request $request) {
        if (str_ends_with($request->url(), '.pdf')) {
            return Http::response('%PDF-1.4 prueba', 200, ['Content-Type' => 'application/pdf']);
        }

        return Http::response($this->respuesta + ['serie' => $request->data()['serie'] ?? 'B001', 'numero' => $request->data()['numero'] ?? 1]);
    });

    $this->as($this->co->owner)->putJson('/api/integrations/invoicing', [
        'provider' => 'nubefact', 'route' => 'https://api.nubefact.com/api/v1/ruta-observados-1234', 'token' => 'token-obs-9876', 'mode' => 'sandbox',
    ])->assertOk();

    $this->boleta = function () {
        $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]]);
        $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => 118, 'doc_type' => 'boleta'])->assertOk();

        return DB::table('invoices')->where('sale_id', $venta['id'])->where('doc_type', 'boleta')->first();
    };
});

it('un comprobante que SUNAT acepta con observaciones queda como observado y muestra la nota', function () {
    $this->respuesta['sunat_responsecode'] = '4252';
    $this->respuesta['sunat_note'] = 'El dato ingresado como dirección no cumple con el formato';

    $boleta = ($this->boleta)();

    expect($boleta->sunat_status)->toBe('observed')
        ->and($boleta->sunat_message)->toContain('observaciones');

    $fila = collect($this->as($this->co->owner)->getJson('/api/invoices')->json('invoices'))->firstWhere('id', $boleta->id);
    expect($fila['state_label'])->toBe('Aceptado por SUNAT con observaciones')
        ->and($fila['can_resend'])->toBeFalse();
});

it('un código de rechazo sigue siendo rechazado', function () {
    $this->respuesta['aceptada_por_sunat'] = false;
    $this->respuesta['sunat_responsecode'] = '2017';

    expect(($this->boleta)()->sunat_status)->toBe('rejected');
});

it('los archivos se bajan con un enlace firmado propio que vence', function () {
    $boleta = ($this->boleta)();
    $fila = collect($this->as($this->co->owner)->getJson('/api/invoices')->json('invoices'))->firstWhere('id', $boleta->id);

    expect($fila['pdf_url'])->not->toContain('nubefact.com')
        ->and($fila['pdf_url'])->toContain('signature=');

    $enlace = parse_url($fila['pdf_url']);
    $ruta = $enlace['path'] . '?' . $enlace['query'];

    $this->app['auth']->forgetGuards();
    $this->get($ruta)->assertOk()->assertHeader('Content-Type', 'application/pdf');

    $this->get(str_replace('signature=', 'signature=x', $ruta))->assertForbidden();

    $this->travel(31)->minutes();
    $this->get($ruta)->assertForbidden();
});
