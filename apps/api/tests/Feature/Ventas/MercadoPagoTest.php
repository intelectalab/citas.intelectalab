<?php

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

const SECRETO = 'secreto-de-avisos-de-prueba';

function conectarMercadoPago(string $tenantId, ?string $secreto = SECRETO): void
{
    DB::table('tenant_integrations')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'kind' => 'payments', 'provider' => 'mercadopago', 'mode' => 'sandbox',
        'credentials' => Crypt::encryptString(json_encode(['secret_key' => 'TEST-token-de-prueba', 'webhook_secret' => $secreto])),
        'settings' => json_encode(['currency' => 'PEN']), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function mercadoPagoFalso(array &$orden, array &$llamadas): void
{
    Http::fake(function (HttpRequest $request) use (&$orden, &$llamadas) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        $llamadas[] = $request->method() . ' ' . $path;

        if ($request->method() === 'POST' && $path === '/v1/orders') {
            $orden['id'] = 'ORD' . Str::upper(Str::random(10));
            $orden['external_reference'] = $request['external_reference'];
            $orden['total_amount'] = $request['total_amount'];

            return Http::response(['id' => $orden['id'], 'checkout_url' => 'https://www.mercadopago.test/checkout/' . $orden['id']], 201);
        }

        if ($request->method() === 'GET' && str_starts_with($path, '/v1/orders/')) {
            return Http::response($orden);
        }

        if ($request->method() === 'POST' && str_ends_with($path, '/refund')) {
            if (!empty($orden['refund_fails'])) {
                return Http::response(['message' => 'refund not allowed'], 400);
            }

            return Http::response(['id' => $orden['id'], 'transactions' => ['refunds' => [['id' => 'REF-1']]]]);
        }

        return Http::response(['message' => 'no esperado'], 500);
    });
}

function avisoFirmado(string $ordenId, string $secreto = SECRETO): array
{
    $ts = (string) time();
    $requestId = (string) Str::uuid();
    $firma = hash_hmac('sha256', 'id:' . strtolower($ordenId) . ";request-id:{$requestId};ts:{$ts};", $secreto);

    return ['x-signature' => "ts={$ts},v1={$firma}", 'x-request-id' => $requestId];
}

beforeEach(function () {
    $this->co = $this->company();
    $this->clienta = $this->customer($this->co->tenant);
    $this->servicio = $this->servicePriced($this->co->tenant, 118, 'Tratamiento de prueba');
    $this->venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]], ['customer_id' => $this->clienta->id]);
    $this->orden = [];
    $this->llamadas = [];
});

describe('link de pago simulado', function () {
    it('el pago aprobado cobra la venta y emite el comprobante', function () {
        $link = $this->as($this->co->owner)->postJson("/api/sales/{$this->venta['id']}/links")->assertCreated()->json();
        $link = (array) DB::table('payment_links')->where('id', $link['id'])->first();

        $this->postJson("/api/pay/{$link['token']}/simulate", ['result' => 'approved'])->assertOk()->assertJsonPath('result.status', 'paid');

        expect($this->sale($this->venta['id'])->status)->toBe('paid')
            ->and(DB::table('invoices')->where('sale_id', $this->venta['id'])->count())->toBe(1)
            ->and(DB::table('payments')->where('sale_id', $this->venta['id'])->value('method'))->toBe('link');
    });

    it('el pago rechazado deja la venta por cobrar y el link sigue esperando', function () {
        $link = $this->as($this->co->owner)->postJson("/api/sales/{$this->venta['id']}/links")->assertCreated()->json();
        $link = (array) DB::table('payment_links')->where('id', $link['id'])->first();

        $this->postJson("/api/pay/{$link['token']}/simulate", ['result' => 'rejected'])->assertOk()->assertJsonPath('result.status', 'rejected');

        expect($this->sale($this->venta['id'])->status)->toBe('pending')
            ->and(DB::table('payment_links')->where('token', $link['token'])->value('status'))->toBe('pending');
    });

    it('no crea un segundo link mientras el primero espera pago', function () {
        $this->as($this->co->owner)->postJson("/api/sales/{$this->venta['id']}/links")->assertCreated();
        $this->as($this->co->owner)->postJson("/api/sales/{$this->venta['id']}/links")->assertStatus(422)->assertJsonValidationErrors('link');
    });

    it('al cobrar en la sede, el link pendiente se anula', function () {
        $link = $this->as($this->co->owner)->postJson("/api/sales/{$this->venta['id']}/links")->assertCreated()->json();
        $link = (array) DB::table('payment_links')->where('id', $link['id'])->first();

        $this->pay($this->co, $this->venta['id'], ['method' => 'card', 'amount' => 118])->assertOk();

        expect(DB::table('payment_links')->where('token', $link['token'])->value('status'))->toBe('cancelled');
        $this->postJson("/api/pay/{$link['token']}/simulate", ['result' => 'approved'])->assertStatus(422);
        expect(DB::table('payments')->where('sale_id', $this->venta['id'])->count())->toBe(1);
    });

    it('en producción la simulación no existe: sin pasarela conectada no hay links', function () {
        config(['payments.simulation' => false]);

        $this->as($this->co->owner)->postJson("/api/sales/{$this->venta['id']}/links")->assertStatus(422)->assertJsonValidationErrors('link');
    });
});

describe('Mercado Pago', function () {
    beforeEach(function () {
        conectarMercadoPago($this->co->tenant->id);
        mercadoPagoFalso($this->orden, $this->llamadas);

        $link = $this->as($this->co->owner)->postJson("/api/sales/{$this->venta['id']}/links")->assertCreated()->json();
        $this->link = (array) DB::table('payment_links')->where('id', $link['id'])->first();
        $this->postJson("/api/pay/{$this->link['token']}/start")->assertOk()->assertJsonPath('checkout_url', 'https://www.mercadopago.test/checkout/' . $this->orden['id']);

        $this->aviso = fn (array $cabeceras = null, ?string $empresa = null) => $this->withHeaders($cabeceras ?? avisoFirmado($this->orden['id']))
            ->postJson('/api/webhooks/payments/mercadopago/' . ($empresa ?? $this->co->tenant->id) . "?data.id={$this->orden['id']}&type=order", ['type' => 'order', 'data' => ['id' => $this->orden['id']]]);
    });

    it('crea la orden en Mercado Pago con el monto y la referencia del link', function () {
        expect($this->orden['total_amount'])->toBe('118.00')
            ->and($this->orden['external_reference'])->toBe($this->link['token'])
            ->and(DB::table('payment_links')->where('token', $this->link['token'])->value('provider'))->toBe('mercadopago');
    });

    it('el aviso de pago aprobado cobra la venta', function () {
        $this->orden += ['status' => 'processed', 'total_paid_amount' => '118.00'];

        ($this->aviso)()->assertOk()->assertJsonPath('status', 'paid');

        $pago = DB::table('payments')->where('sale_id', $this->venta['id'])->first();

        expect($this->sale($this->venta['id'])->status)->toBe('paid')
            ->and($pago->provider)->toBe('mercadopago')
            ->and($pago->external_reference)->toBe($this->orden['id'])
            ->and(DB::table('invoices')->where('sale_id', $this->venta['id'])->count())->toBe(1);
    });

    it('si el mismo aviso llega dos veces, el pago se registra una sola vez', function () {
        $this->orden += ['status' => 'processed', 'total_paid_amount' => '118.00'];

        ($this->aviso)()->assertOk()->assertJsonPath('status', 'paid');
        ($this->aviso)()->assertOk()->assertJsonPath('status', 'duplicate');

        expect(DB::table('payments')->where('sale_id', $this->venta['id'])->count())->toBe(1);
    });

    it('rechaza un aviso con la firma equivocada', function () {
        $this->orden += ['status' => 'processed', 'total_paid_amount' => '118.00'];

        ($this->aviso)(avisoFirmado($this->orden['id'], 'otra-clave'))->assertStatus(400);
        ($this->aviso)([])->assertStatus(400);

        expect($this->sale($this->venta['id'])->status)->toBe('pending');
    });

    it('no cobra la venta si en Mercado Pago se pagó menos', function () {
        $this->orden += ['status' => 'processed', 'total_paid_amount' => '50.00', 'total_amount' => '50.00'];

        ($this->aviso)()->assertOk();

        expect($this->sale($this->venta['id'])->status)->toBe('pending')
            ->and(DB::table('payments')->where('sale_id', $this->venta['id'])->exists())->toBeFalse();
    });

    it('un pago fallido no cambia nada y el link sigue esperando', function () {
        $this->orden += ['status' => 'failed', 'total_paid_amount' => '0.00'];

        ($this->aviso)()->assertOk()->assertJsonPath('status', 'rejected');

        expect($this->sale($this->venta['id'])->status)->toBe('pending')
            ->and(DB::table('payment_links')->where('token', $this->link['token'])->value('status'))->toBe('pending');
    });

    it('un aviso enviado a la dirección de otra empresa no toca esta venta', function () {
        $otra = $this->company();
        conectarMercadoPago($otra->tenant->id);
        $this->orden += ['status' => 'processed', 'total_paid_amount' => '118.00'];

        ($this->aviso)(null, $otra->tenant->id)->assertOk()->assertJsonPath('status', 'ignored');

        expect($this->sale($this->venta['id'])->status)->toBe('pending');
    });

    it('si se pagó en la sede y luego entra el pago en línea, lo devuelve solo', function () {
        $this->pay($this->co, $this->venta['id'], ['method' => 'card', 'amount' => 118])->assertOk();
        $this->orden += ['status' => 'processed', 'total_paid_amount' => '118.00'];

        ($this->aviso)()->assertOk()->assertJsonPath('status', 'refunded');

        expect(collect($this->llamadas)->contains("POST /v1/orders/{$this->orden['id']}/refund"))->toBeTrue()
            ->and(DB::table('payments')->where('sale_id', $this->venta['id'])->count())->toBe(1);
    });

    it('la devolución por la pasarela la pide a Mercado Pago', function () {
        $this->orden += ['status' => 'processed', 'total_paid_amount' => '118.00', 'transactions' => ['payments' => [['id' => 'PAY-1']]]];
        ($this->aviso)()->assertOk();

        $this->as($this->co->owner)->postJson("/api/sales/{$this->venta['id']}/refunds", ['amount' => 18, 'method' => 'gateway', 'reason' => 'Descuento olvidado'])->assertOk();

        $devolucion = collect(Http::recorded())->map(fn ($par) => $par[0])->first(fn ($r) => str_ends_with(parse_url($r->url(), PHP_URL_PATH), '/refund'));

        expect($devolucion)->not->toBeNull()
            ->and($devolucion['transactions'][0]['amount'])->toBe('18.00')
            ->and($this->sale($this->venta['id'])->status)->toBe('partially_refunded');
    });

    it('si Mercado Pago no acepta la devolución, no se registra', function () {
        $this->orden += ['status' => 'processed', 'total_paid_amount' => '118.00'];
        ($this->aviso)()->assertOk();

        $this->orden['refund_fails'] = true;

        $this->as($this->co->owner)->postJson("/api/sales/{$this->venta['id']}/refunds", ['amount' => 18, 'method' => 'gateway', 'reason' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        expect(DB::table('sale_refunds')->where('sale_id', $this->venta['id'])->exists())->toBeFalse();
    });
});
