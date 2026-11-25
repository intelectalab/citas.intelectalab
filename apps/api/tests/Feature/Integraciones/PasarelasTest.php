<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

const NIUBIZ = 'https://apisandbox.vnforappstest.com';
const IZIPAY = 'https://api.micuentaweb.pe/api-payment/V4/';

beforeEach(function () {
    $this->co = $this->company();
    DB::table('companies')->where('tenant_id', $this->co->tenant->id)->update(['tax_id' => '20123456789']);
    $this->servicio = $this->servicePriced($this->co->tenant, 80, 'Limpieza facial');
    $this->clienta = $this->customer($this->co->tenant, 'Rosa', 'Quispe');
    DB::table('customers')->where('id', $this->clienta->id)->update(['email' => 'rosa@correo.test']);

    $this->linkFor = function () {
        $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id]], ['customer_id' => $this->clienta->id]);
        $link = $this->as($this->co->owner)->postJson("/api/sales/{$venta['id']}/links")->assertCreated()->json();
        $this->app['auth']->forgetGuards();

        return [$venta, DB::table('payment_links')->where('id', $link['id'])->first()];
    };

    $this->connectNiubiz = function () {
        Http::fake([NIUBIZ . '/api.security/v1/security' => Http::response('eyJ.token.niubiz', 201)]);
        $this->as($this->co->owner)->putJson('/api/integrations/payments', [
            'provider' => 'niubiz', 'mode' => 'sandbox', 'public_key' => '456879852', 'username' => 'integraciones@niubiz.com.pe', 'secret_key' => '_7z3@8fF',
        ])->assertOk();
    };

    $this->connectIzipay = function () {
        Http::fake([IZIPAY . 'Charge/SDKTest' => Http::response(['status' => 'SUCCESS', 'answer' => ['value' => 'agenda']])]);
        $this->as($this->co->owner)->putJson('/api/integrations/payments', [
            'provider' => 'izipay', 'public_key' => '69876357', 'secret_key' => 'testpassword_DEMOPRIVATEKEY23G4475zXZQ2UA5x7M', 'webhook_secret' => 'testsha256_clave',
        ])->assertOk();
    };
});

describe('Niubiz', function () {
    it('pide código de comercio con números y usuario antes de conectar', function () {
        $this->as($this->co->owner)->putJson('/api/integrations/payments', ['provider' => 'niubiz', 'public_key' => 'abc', 'username' => 'x', 'secret_key' => 'y'])
            ->assertStatus(422)->assertJsonValidationErrors('public_key');
        $this->as($this->co->owner)->putJson('/api/integrations/payments', ['provider' => 'niubiz', 'public_key' => '456879852', 'secret_key' => 'y'])
            ->assertStatus(422)->assertJsonValidationErrors('username');
    });

    it('no guarda credenciales que Niubiz rechaza y lo deja en el registro', function () {
        Http::fake([NIUBIZ . '/api.security/v1/security' => Http::response('Unauthorized access', 401)]);
        $this->as($this->co->owner)->putJson('/api/integrations/payments', ['provider' => 'niubiz', 'public_key' => '456879852', 'username' => 'u@x.pe', 'secret_key' => 'mala'])
            ->assertStatus(422)->assertJsonValidationErrors('secret_key');

        expect(DB::table('tenant_integrations')->where('tenant_id', $this->co->tenant->id)->where('provider', 'niubiz')->exists())->toBeFalse()
            ->and(DB::table('integration_logs')->where('tenant_id', $this->co->tenant->id)->where('provider', 'niubiz')->value('ok'))->toBeFalse();
    });

    it('cobra un link: abre la sesión, autoriza con el token y marca el link pagado', function () {
        ($this->connectNiubiz)();
        [, $link] = ($this->linkFor)();
        expect($link->provider)->toBe('niubiz');

        $start = $this->postJson("/api/pay/{$link->token}/start")->assertOk()->json();
        expect($start['checkout_url'])->toContain("/api/pay/{$link->token}/niubiz");
        $purchase = DB::table('payment_links')->where('id', $link->id)->value('external_id');

        Http::fake([
            NIUBIZ . '/api.security/v1/security' => Http::response('eyJ.token.niubiz', 201),
            NIUBIZ . '/api.ecommerce/v2/ecommerce/token/session/456879852' => Http::response(['sessionKey' => 'ses-123', 'expirationTime' => 1700000000000]),
            NIUBIZ . '/api.authorization/v3/authorization/ecommerce/456879852' => Http::response([
                'header'  => ['ecoreTransactionUUID' => 'uuid-1'],
                'order'   => ['purchaseNumber' => $purchase, 'amount' => 80, 'currency' => 'PEN', 'actionCode' => '000', 'transactionId' => '993200001'],
                'dataMap' => ['ACTION_CODE' => '000', 'STATUS' => 'Authorized', 'TRANSACTION_ID' => '993200001', 'ACTION_DESCRIPTION' => 'Aprobado y completado con exito'],
            ]),
        ]);

        $page = $this->get("/api/pay/{$link->token}/niubiz")->assertOk();
        expect($page->getContent())->toContain('data-sessiontoken="ses-123"')->toContain('data-merchantid="456879852"')->toContain("data-purchasenumber=\"{$purchase}\"");

        $this->post("/api/pay/{$link->token}/niubiz", ['transactionToken' => 'ABCDEF1234567890ABCDEF1234567890', 'customerEmail' => 'rosa@correo.test', 'channel' => 'web'])->assertRedirect();

        Http::assertSent(fn ($r) => str_contains($r->url(), 'authorization/ecommerce') && $r['order']['tokenId'] === 'ABCDEF1234567890ABCDEF1234567890' && $r['order']['purchaseNumber'] === $purchase && $r->header('Authorization')[0] === 'eyJ.token.niubiz');
        expect(DB::table('payment_links')->where('id', $link->id)->value('status'))->toBe('paid')
            ->and(DB::table('payments')->where('sale_id', $link->sale_id)->value('external_reference'))->toBe('993200001');
    });

    it('un pago denegado deja el link esperando', function () {
        ($this->connectNiubiz)();
        [, $link] = ($this->linkFor)();
        $this->postJson("/api/pay/{$link->token}/start")->assertOk();

        Http::fake([
            NIUBIZ . '/api.security/v1/security' => Http::response('eyJ.token.niubiz', 201),
            NIUBIZ . '/api.authorization/*' => Http::response(['errorCode' => 400, 'errorMessage' => 'NOT AUTHORIZED', 'data' => ['ACTION_CODE' => '116', 'STATUS' => 'Not Authorized', 'ACTION_DESCRIPTION' => 'Fondos insuficientes']], 400),
        ]);

        $this->post("/api/pay/{$link->token}/niubiz", ['transactionToken' => 'ABCDEF1234567890ABCDEF1234567890'])->assertRedirect();

        expect(DB::table('payment_links')->where('id', $link->id)->value('status'))->toBe('pending')
            ->and(DB::table('payment_events')->where('payment_link_id', $link->id)->value('event'))->toBe('payment.rejected');
    });

    it('devuelve por la API de devoluciones con el RUC de la empresa', function () {
        ($this->connectNiubiz)();
        [$venta, $link] = ($this->linkFor)();
        $this->postJson("/api/pay/{$link->token}/start")->assertOk();
        Http::fake([
            NIUBIZ . '/api.security/v1/security' => Http::response('eyJ.token.niubiz', 201),
            NIUBIZ . '/api.authorization/*' => Http::response(['order' => ['amount' => 80, 'transactionId' => '993200002'], 'dataMap' => ['ACTION_CODE' => '000', 'STATUS' => 'Authorized', 'TRANSACTION_ID' => '993200002']]),
            'https://apitestenv.vnforapps.com/api.refund/v1/refund/456879852/993200002' => Http::response(['errorCode' => 0, 'errorMessage' => 'OK', 'data' => ['CODERROR' => '100', 'CODIGODEVOLUCION' => 'DEV-77']]),
        ]);
        $this->post("/api/pay/{$link->token}/niubiz", ['transactionToken' => 'ABCDEF1234567890ABCDEF1234567890'])->assertRedirect();

        $payment = DB::table('payments')->where('sale_id', $venta['id'])->first();
        $result = \App\Support\PaymentLinks::providerFor($this->co->tenant->id, 'niubiz')->refund($payment, 30);

        expect($result['ok'])->toBeTrue()->and($result['external_id'])->toBe('DEV-77');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.refund') && $r['ruc'] === '20123456789' && (float) $r['amount'] === 30.0);
    });
});

describe('Izipay', function () {
    it('la contraseña debe ser la de la API REST', function () {
        $this->as($this->co->owner)->putJson('/api/integrations/payments', ['provider' => 'izipay', 'public_key' => '69876357', 'secret_key' => 'otra'])
            ->assertStatus(422)->assertJsonValidationErrors('secret_key');
    });

    it('crea la orden de pago, manda al cliente a Izipay y confirma con el aviso firmado', function () {
        ($this->connectIzipay)();
        expect(DB::table('tenant_integrations')->where('tenant_id', $this->co->tenant->id)->where('provider', 'izipay')->value('mode'))->toBe('sandbox');
        [, $link] = ($this->linkFor)();

        Http::fake([
            IZIPAY . 'Charge/CreatePaymentOrder' => Http::response(['status' => 'SUCCESS', 'answer' => ['paymentOrderId' => 'po-1', 'paymentURL' => 'https://secure.micuentaweb.pe/t/abc', 'paymentOrderStatus' => 'RUNNING']]),
            IZIPAY . 'Charge/PaymentOrder/Get' => Http::response(['status' => 'SUCCESS', 'answer' => ['paymentOrderId' => 'po-1', 'paymentOrderStatus' => 'PAID', 'amount' => 8000, 'currency' => 'PEN']]),
        ]);

        $start = $this->postJson("/api/pay/{$link->token}/start")->assertOk()->json();
        expect($start['checkout_url'])->toBe('https://secure.micuentaweb.pe/t/abc');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'CreatePaymentOrder') && $r['amount'] === 8000 && $r['orderId'] === $link->token && $r['channelOptions']['channelType'] === 'URL');

        $answer = json_encode(['orderStatus' => 'PAID', 'orderDetails' => ['orderId' => $link->token, 'orderTotalAmount' => 8000]]);
        $this->post("/api/webhooks/payments/izipay/{$this->co->tenant->id}", ['kr-answer' => $answer, 'kr-hash' => 'mal', 'kr-hash-key' => 'password', 'kr-hash-algorithm' => 'sha256_hmac'])->assertStatus(400);

        $hash = hash_hmac('sha256', $answer, 'testpassword_DEMOPRIVATEKEY23G4475zXZQ2UA5x7M');
        $this->post("/api/webhooks/payments/izipay/{$this->co->tenant->id}", ['kr-answer' => $answer, 'kr-hash' => $hash, 'kr-hash-key' => 'password', 'kr-hash-algorithm' => 'sha256_hmac'])->assertOk();

        expect(DB::table('payment_links')->where('id', $link->id)->value('status'))->toBe('paid');

        $this->post("/api/webhooks/payments/izipay/{$this->co->tenant->id}", ['kr-answer' => $answer, 'kr-hash' => $hash, 'kr-hash-key' => 'password', 'kr-hash-algorithm' => 'sha256_hmac'])->assertOk()->assertJsonPath('status', 'duplicate');
    });

    it('probar conexión usa la prueba de Izipay y queda en el registro', function () {
        Http::fake([IZIPAY . 'Charge/SDKTest' => Http::sequence()
            ->push(['status' => 'SUCCESS', 'answer' => ['value' => 'agenda']])
            ->push(['status' => 'ERROR', 'answer' => ['errorCode' => 'INT_905', 'errorMessage' => 'invalid credentials']], 401)]);
        $this->as($this->co->owner)->putJson('/api/integrations/payments', ['provider' => 'izipay', 'public_key' => '69876357', 'secret_key' => 'testpassword_abc'])->assertOk();

        $this->as($this->co->owner)->postJson('/api/integrations/payments/test', ['provider' => 'izipay'])->assertOk()->assertJsonPath('ok', false);
        $log = $this->as($this->co->owner)->getJson('/api/integrations')->assertOk()->json('payments.log');

        expect($log[0]['provider'])->toBe('izipay')->and($log[0]['ok'])->toBeFalse()->and($log[0]['message'])->toContain('usuario o la contraseña');
    });
});

describe('Culqi 3DS', function () {
    it('si el banco pide verificar, devuelve los datos del 3DS y reintenta con la autenticación', function () {
        Http::fake(['https://api.culqi.com/v2/charges*' => Http::sequence()
            ->push(['object' => 'list', 'data' => []])
            ->push(['action_code' => 'REVIEW', 'user_message' => 'Se requiere autenticación'], 200)
            ->push(['object' => 'charge', 'id' => 'chr_test_1', 'amount' => 8000, 'outcome' => ['type' => 'venta_exitosa']], 201)]);
        $this->as($this->co->owner)->putJson('/api/integrations/payments', ['provider' => 'culqi', 'public_key' => 'pk_test_abc', 'secret_key' => 'sk_test_abc'])->assertOk();
        [, $link] = ($this->linkFor)();

        $first = $this->postJson("/api/pay/{$link->token}/culqi", ['token' => 'tkn_test_1'])->assertOk()->json('result');
        expect($first['status'])->toBe('needs_3ds')->and($first['three_ds']['public_key'])->toBe('pk_test_abc');
        expect(DB::table('payment_links')->where('id', $link->id)->value('status'))->toBe('pending');

        $this->postJson("/api/pay/{$link->token}/culqi", ['token' => 'tkn_test_1', 'device' => 'dev-1', 'authentication_3ds' => ['eci' => '05', 'xid' => 'x', 'cavv' => 'c', 'protocolVersion' => '2.1.0', 'directoryServerTransactionId' => 'd']])->assertOk();

        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/charges') && $r->method() === 'POST' && ($r['authentication_3DS']['eci'] ?? null) === '05' && ($r['antifraud_details']['device_finger_print_id'] ?? null) === 'dev-1');
        expect(DB::table('payment_links')->where('id', $link->id)->value('status'))->toBe('paid');
    });
});
