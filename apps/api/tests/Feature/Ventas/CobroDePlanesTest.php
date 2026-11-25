<?php

use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Dunning;
use App\Support\PlanTransfers;
use App\Support\PlatformMail;
use App\Support\Subscriptions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function conectarPlataforma(string $provider): void
{
    $keys = $provider === 'culqi'
        ? ['public_key' => 'pk_test_plataforma', 'secret_key' => 'sk_test_plataforma']
        : ['public_key' => 'TEST-public', 'secret_key' => 'TEST-token-plataforma'];

    DB::table('platform_integrations')->insert([
        'id' => (string) Str::uuid(), 'kind' => 'payments', 'provider' => $provider, 'mode' => 'sandbox',
        'credentials' => Crypt::encryptString(json_encode($keys)), 'settings' => json_encode(['currency' => 'PEN']),
        'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    Tests\TestCase::forgetCaches();
}

function conectarCulqiEmpresa(string $tenantId): void
{
    DB::table('tenant_integrations')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'kind' => 'payments', 'provider' => 'culqi', 'mode' => 'sandbox',
        'credentials' => Crypt::encryptString(json_encode(['public_key' => 'pk_test_empresa', 'secret_key' => 'sk_test_empresa'])),
        'settings' => json_encode(['currency' => 'PEN']), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function conectarMercadoPagoEmpresa(string $tenantId): void
{
    DB::table('tenant_integrations')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'kind' => 'payments', 'provider' => 'mercadopago', 'mode' => 'sandbox',
        'credentials' => Crypt::encryptString(json_encode(['public_key' => 'TEST-public', 'secret_key' => 'TEST-token-empresa'])),
        'settings' => json_encode(['currency' => 'PEN']), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function pasarelasFalsas(array &$estado): void
{
    Http::fake(function (HttpRequest $request) use (&$estado) {
        $url = $request->url();
        $path = (string) parse_url($url, PHP_URL_PATH);
        $metodo = $request->method();
        $estado['llamadas'][] = $metodo . ' ' . $path;

        if (str_contains($url, 'culqi.com')) {
            if (!empty($estado['caido'])) {
                return Http::response(['object' => 'error', 'type' => 'api_error', 'merchant_message' => 'Servicio no disponible'], 503);
            }

            if ($metodo === 'POST' && $path === '/v2/customers') {
                return Http::response(['object' => 'customer', 'id' => 'cus_test_1'], 201);
            }

            if ($metodo === 'POST' && $path === '/v2/cards') {
                if ($request['token_id'] === 'tkn_test_banco') {
                    return Http::response(['action_code' => 'REVIEW', 'user_message' => 'Se requiere autenticación'], 200);
                }

                $estado['tarjetas'] = ($estado['tarjetas'] ?? 0) + 1;

                return Http::response(['object' => 'card', 'id' => 'crd_test_' . $estado['tarjetas'], 'customer_id' => $request['customer_id'],
                    'source' => ['card_number' => '411111******1111', 'last_four' => '1111', 'iin' => ['card_brand' => 'Visa']]], 201);
            }

            if ($metodo === 'DELETE' && str_starts_with($path, '/v2/cards/')) {
                return Http::response(['deleted' => true]);
            }

            if ($metodo === 'POST' && $path === '/v2/charges') {
                $estado['cobros'][] = ['amount' => $request['amount'], 'source_id' => $request['source_id'], 'metadata' => $request['metadata'] ?? []];

                if (!empty($estado['declinar'])) {
                    return Http::response(['object' => 'error', 'type' => 'card_error', 'user_message' => 'Tu tarjeta no tiene fondos suficientes.', 'decline_code' => 'insufficient_funds'], 402);
                }

                $id = 'chr_test_' . count($estado['cobros']);
                $estado['cargos'][$id] = ['object' => 'charge', 'id' => $id, 'amount' => $request['amount'], 'outcome' => ['type' => 'venta_exitosa'], 'metadata' => $request['metadata'] ?? []];

                return Http::response($estado['cargos'][$id], 201);
            }

            if ($metodo === 'GET' && str_starts_with($path, '/v2/charges/')) {
                $id = substr($path, strlen('/v2/charges/'));

                return isset($estado['cargos'][$id]) ? Http::response($estado['cargos'][$id]) : Http::response(['object' => 'error'], 404);
            }

            if ($metodo === 'GET' && $path === '/v2/charges') {
                return Http::response(['data' => [], 'paging' => []]);
            }
        }

        if (str_contains($url, 'mercadopago')) {
            if ($metodo === 'POST' && $path === '/v1/orders') {
                return Http::response(['id' => 'ORD-RESPALDO', 'checkout_url' => 'https://www.mercadopago.test/checkout/ORD-RESPALDO'], 201);
            }
        }

        return Http::response(['message' => 'no esperado'], 500);
    });
}

function suscripcion(string $tenantId): Subscription
{
    Subscriptions::forget($tenantId);

    return Subscriptions::latest($tenantId);
}

function tarjetaGuardada(Subscription $subscription, string $provider = 'culqi', string $last4 = '1111'): void
{
    $subscription->update([
        'external_provider' => $provider, 'external_subscription_id' => $provider === 'culqi' ? 'crd_test_guardada' : 'sim_card',
        'card_status' => 'authorized', 'card_brand' => 'visa', 'card_last4' => $last4, 'card_authorized_at' => now(),
    ]);
    Subscriptions::forget($subscription->tenant_id);
}

function adminPlataforma(): User
{
    return User::forceCreate([
        'id' => (string) Str::uuid(),
        'first_name' => 'Admin', 'last_name' => 'Plataforma', 'email' => 'admin.' . Str::lower(Str::random(5)) . '@plataforma.test',
        'password_hash' => bcrypt('clave-de-prueba-123'), 'status' => 'active', 'tenant_id' => null,
    ]);
}

function correrMora($test, int $dias, int $horas = 2): array
{
    $test->travelTo($test->fin->copy()->addDays($dias)->addHours($horas));
    $buzon = PlatformMail::$outbox;
    Tests\TestCase::forgetCaches();
    PlatformMail::$outbox = $buzon ?? [];

    return Dunning::run();
}

function crearLinkCulqi($test): object
{
    Tests\TestCase::forgetCaches();
    PlatformMail::$outbox = [];
    \Laravel\Sanctum\Sanctum::actingAs($test->co->owner, ['*']);
    $link = $test->postJson("/api/sales/{$test->venta['id']}/links")->assertCreated()->json();

    return DB::table('payment_links')->where('id', $link['id'])->first();
}

beforeEach(function () {
    $this->estado = ['llamadas' => [], 'cobros' => [], 'cargos' => []];
    pasarelasFalsas($this->estado);
});

describe('Culqi para cobrar el plan', function () {
    beforeEach(function () {
        conectarPlataforma('culqi');
        $this->co = $this->company(['trial' => true]);
    });

    it('guardar la tarjeta solo manda el token a Culqi y guarda marca y últimos 4, sin cobrar en la prueba', function () {
        $res = $this->as($this->co->owner)->postJson('/api/billing/card')->assertOk();
        expect($res->json('culqi.public_key'))->toBe('pk_test_plataforma')
            ->and($res->json('culqi.action'))->toBe('card');

        $this->postJson('/api/billing/culqi', ['payment_id' => $res->json('culqi.payment_id'), 'token' => 'tkn_test_bueno'])->assertOk();

        $metodo = DB::table('saas_payment_methods')->where('tenant_id', $this->co->tenant->id)->first();
        $sub = suscripcion($this->co->tenant->id);

        expect($metodo->card_ref)->toBe('crd_test_1')
            ->and($metodo->last4)->toBe('1111')
            ->and($metodo->brand)->toBe('visa')
            ->and($sub->card_status)->toBe('authorized')
            ->and($sub->external_provider)->toBe('culqi')
            ->and($sub->status)->toBe('trialing')
            ->and($this->estado['cobros'])->toBeEmpty();
    });

    it('activar el plan durante la prueba guarda la tarjeta, cobra el primer periodo y pasa a activo', function () {
        $res = $this->as($this->co->owner)->postJson('/api/billing/activate')->assertOk();
        $this->postJson('/api/billing/culqi', ['payment_id' => $res->json('culqi.payment_id'), 'token' => 'tkn_test_bueno'])->assertOk();

        $sub = suscripcion($this->co->tenant->id);
        $precio = Subscriptions::price($sub->plan, $sub->billing_cycle);

        expect($sub->status)->toBe('active')
            ->and($this->estado['cobros'])->toHaveCount(1)
            ->and($this->estado['cobros'][0]['amount'])->toBe((int) round($precio * 100))
            ->and($this->estado['cobros'][0]['source_id'])->toBe('crd_test_1')
            ->and(DB::table('subscription_invoices')->where('subscription_id', $sub->id)->value('billing_reason'))->toBe('subscription_create');
    });

    it('con la tarjeta ya guardada, activar el plan cobra al momento sin pedirla otra vez', function () {
        tarjetaGuardada(suscripcion($this->co->tenant->id));

        $this->as($this->co->owner)->postJson('/api/billing/activate')->assertOk()->assertJsonPath('billing.state', 'active');

        expect($this->estado['cobros'])->toHaveCount(1)
            ->and($this->estado['cobros'][0]['source_id'])->toBe('crd_test_guardada');
    });

    it('una tarjeta que pide verificación del banco se rechaza con un mensaje claro', function () {
        $res = $this->as($this->co->owner)->postJson('/api/billing/card')->assertOk();

        $this->postJson('/api/billing/culqi', ['payment_id' => $res->json('culqi.payment_id'), 'token' => 'tkn_test_banco'])
            ->assertStatus(422)->assertJsonFragment(['plan' => ['Tu banco pide una verificación adicional para esta tarjeta y todavía no podemos hacerla. Prueba con otra tarjeta o paga con otro medio.']]);

        expect(suscripcion($this->co->tenant->id)->card_status)->toBeNull();
    });

    it('no acepta algo que no sea un token de Culqi', function () {
        $res = $this->as($this->co->owner)->postJson('/api/billing/card')->assertOk();

        $this->postJson('/api/billing/culqi', ['payment_id' => $res->json('culqi.payment_id'), 'token' => '4111111111111111'])->assertStatus(422);
    });
});

describe('Pagar ahora con Culqi y Mercado Pago de respaldo', function () {
    beforeEach(function () {
        conectarPlataforma('culqi');
        $this->co = $this->company();
        $sub = suscripcion($this->co->tenant->id);
        $sub->update(['ends_at' => now()->subDays(2)]);
        Subscriptions::forget($this->co->tenant->id);
    });

    it('el pago aprobado deja el plan al día', function () {
        expect(Subscriptions::state($this->co->tenant->id)['state'])->toBe('grace');

        $res = $this->as($this->co->owner)->postJson('/api/billing/pay')->assertOk();
        $this->postJson('/api/billing/culqi', ['payment_id' => $res->json('culqi.payment_id'), 'token' => 'tkn_test_bueno'])->assertOk()->assertJsonPath('billing.state', 'active');

        expect($this->estado['cobros'][0]['source_id'])->toBe('tkn_test_bueno')
            ->and(DB::table('plan_payments')->where('id', $res->json('culqi.payment_id'))->value('external_id'))->toBe('chr_test_1');
    });

    it('una tarjeta rechazada no paga el plan y muestra el motivo de Culqi', function () {
        $this->estado['declinar'] = true;
        $res = $this->as($this->co->owner)->postJson('/api/billing/pay')->assertOk();

        $this->postJson('/api/billing/culqi', ['payment_id' => $res->json('culqi.payment_id'), 'token' => 'tkn_test_bueno'])
            ->assertStatus(422)->assertJsonFragment(['plan' => ['Tu tarjeta no tiene fondos suficientes.']]);

        expect(DB::table('plan_payments')->where('id', $res->json('culqi.payment_id'))->value('status'))->toBe('failed')
            ->and(Subscriptions::state($this->co->tenant->id)['state'])->toBe('grace');
    });

    it('si Culqi no responde y Mercado Pago está conectado, lleva a Mercado Pago', function () {
        conectarPlataforma('mercadopago');
        $res = $this->as($this->co->owner)->postJson('/api/billing/pay')->assertOk();
        $this->estado['caido'] = true;

        $this->postJson('/api/billing/culqi', ['payment_id' => $res->json('culqi.payment_id'), 'token' => 'tkn_test_bueno'])
            ->assertOk()->assertJsonPath('checkout_url', 'https://www.mercadopago.test/checkout/ORD-RESPALDO');
    });

    it('si la plataforma elige Mercado Pago como principal, se paga con Mercado Pago', function () {
        conectarPlataforma('mercadopago');
        PlatformSetting::put(['plan_primary' => 'mercadopago']);
        Tests\TestCase::forgetCaches();

        $this->as($this->co->owner)->postJson('/api/billing/pay')->assertOk()->assertJsonPath('checkout_url', 'https://www.mercadopago.test/checkout/ORD-RESPALDO');
    });

    it('sin Culqi conectado se usa Mercado Pago aunque Culqi sea el principal', function () {
        DB::table('platform_integrations')->where('provider', 'culqi')->delete();
        conectarPlataforma('mercadopago');

        $this->as($this->co->owner)->postJson('/api/billing/pay')->assertOk()->assertJsonPath('checkout_url', 'https://www.mercadopago.test/checkout/ORD-RESPALDO');
    });

    it('el aviso de Culqi confirma un pago que quedó pendiente', function () {
        $res = $this->as($this->co->owner)->postJson('/api/billing/pay')->assertOk();
        $paymentId = $res->json('culqi.payment_id');
        $this->estado['cargos']['chr_test_aviso'] = ['object' => 'charge', 'id' => 'chr_test_aviso', 'amount' => 100, 'outcome' => ['type' => 'venta_exitosa'], 'metadata' => ['plan_payment' => $paymentId]];

        $this->postJson('/api/webhooks/payments/culqi/platform', ['object' => 'event', 'type' => 'charge.creation.succeeded', 'data' => json_encode(['id' => 'chr_test_aviso'])])
            ->assertOk()->assertJsonPath('status', 'processed');

        expect(DB::table('plan_payments')->where('id', $paymentId)->value('status'))->toBe('paid')
            ->and(Subscriptions::state($this->co->tenant->id)['state'])->toBe('active');
    });

    it('un aviso con un cargo que Culqi no reconoce no paga nada', function () {
        $this->postJson('/api/webhooks/payments/culqi/platform', ['data' => json_encode(['id' => 'chr_test_falso'])])->assertOk()->assertJsonPath('status', 'ignored');

        expect(Subscriptions::state($this->co->tenant->id)['state'])->toBe('grace');
    });

    it('cambiar la tarjeta con el plan vencido cobra lo vencido al momento', function () {
        $res = $this->as($this->co->owner)->postJson('/api/billing/card')->assertOk();
        $this->postJson('/api/billing/culqi', ['payment_id' => $res->json('culqi.payment_id'), 'token' => 'tkn_test_bueno'])->assertOk()->assertJsonPath('billing.state', 'active');

        expect($this->estado['cobros'])->toHaveCount(1)
            ->and(DB::table('saas_dunning_attempts')->where('kind', 'retry')->whereNotNull('actor_id')->count())->toBe(1);
    });
});

describe('prueba gratis sin tarjeta', function () {
    it('la prueba empieza sin pedir tarjeta y dura 14 días en todos los planes', function () {
        foreach ([Tests\TestCase::PLAN_ESENCIAL, Tests\TestCase::PLAN_EMPRESARIAL] as $plan) {
            $co = $this->company(['trial' => true, 'plan' => $plan]);
            $billing = $this->as($co->owner)->getJson('/api/billing')->assertOk()->json('billing');

            expect($billing['state'])->toBe('trial')
                ->and($billing['trial_days_left'])->toBe(14)
                ->and($billing['message'])->toBeNull();
        }
    });

    it('cambiar de plan en la prueba no pide tarjeta', function () {
        conectarPlataforma('culqi');
        $co = $this->company(['trial' => true]);

        $this->as($co->owner)->postJson('/api/billing/plan', ['plan_id' => Tests\TestCase::PLAN_ESENCIAL, 'billing_cycle' => 'monthly'])
            ->assertOk()->assertJsonMissingPath('culqi')->assertJsonMissingPath('checkout_url');

        expect(suscripcion($co->tenant->id)->plan_id)->toBe(Tests\TestCase::PLAN_ESENCIAL);
    });

    it('al vencer la prueba sin pagar tiene los días para pagar antes de quedar en solo lectura', function () {
        $co = $this->company(['trial' => true]);
        $fin = Carbon::parse(suscripcion($co->tenant->id)->trial_ends_at);

        $this->travelTo($fin->copy()->addDay());
        Tests\TestCase::forgetCaches();
        expect(Subscriptions::state($co->tenant->id)['state'])->toBe('grace');

        $this->travelTo($fin->copy()->addDays(16));
        Tests\TestCase::forgetCaches();
        expect(Subscriptions::state($co->tenant->id)['state'])->toBe('locked');
    });
});

describe('mora: cobros, avisos, solo lectura y cancelación', function () {
    beforeEach(function () {
        conectarPlataforma('culqi');
        $this->co = $this->company();
        $this->fin = Carbon::parse(suscripcion($this->co->tenant->id)->ends_at);
    });

    it('el día del vencimiento cobra con la tarjeta guardada y renueva el periodo', function () {
        tarjetaGuardada(suscripcion($this->co->tenant->id));

        correrMora($this, 0);

        $sub = suscripcion($this->co->tenant->id);
        expect($this->estado['cobros'])->toHaveCount(1)
            ->and(Carbon::parse($sub->ends_at)->gt($this->fin))->toBeTrue()
            ->and(DB::table('saas_dunning_attempts')->where('kind', 'retry')->value('result'))->toBe('ok');
    });

    it('si la tarjeta falla, reintenta los días 0, 1, 3 y 7 y avisa los días 1, 3, 7 y 12, sin repetir', function () {
        tarjetaGuardada(suscripcion($this->co->tenant->id));
        $this->estado['declinar'] = true;

        foreach ([0, 1, 1, 2, 3, 5, 7, 7, 10, 12, 13] as $dia) {
            correrMora($this, $dia);
        }

        $intentos = DB::table('saas_dunning_attempts')->whereNull('actor_id');
        expect((clone $intentos)->where('kind', 'retry')->orderBy('day')->pluck('day')->all())->toBe([0, 1, 3, 7])
            ->and((clone $intentos)->where('kind', 'remind')->orderBy('day')->pluck('day')->all())->toBe([1, 3, 7, 12])
            ->and($this->estado['cobros'])->toHaveCount(4)
            ->and(collect(PlatformMail::$outbox)->where('subject', 'Tu plan está vencido')->count())->toBe(4)
            ->and(Subscriptions::state($this->co->tenant->id)['state'])->toBe('grace');
    });

    it('el día 15 queda en solo lectura, el 45 se cancela, y pagando vuelve a estar activa', function () {
        correrMora($this, 15);
        expect(Subscriptions::state($this->co->tenant->id)['state'])->toBe('locked')
            ->and(DB::table('saas_dunning_attempts')->where('kind', 'readonly')->count())->toBe(1);

        $this->as($this->co->owner)->postJson('/api/branches', ['name' => 'Otra sede'])->assertStatus(423)->assertJsonPath('code', 'TENANT_SUSPENDED');

        correrMora($this, 45);
        $sub = suscripcion($this->co->tenant->id);
        expect($sub->status)->toBe('canceled')
            ->and(Subscriptions::state($this->co->tenant->id)['reason'])->toBe('canceled');

        $res = $this->as($this->co->owner)->postJson('/api/billing/pay')->assertOk();
        $this->postJson('/api/billing/culqi', ['payment_id' => $res->json('culqi.payment_id'), 'token' => 'tkn_test_bueno'])->assertOk();

        expect(Subscriptions::state($this->co->tenant->id)['state'])->toBe('active');
    });

    it('la prórroga de la consola mueve la fecha de solo lectura 7 días y queda registrada', function () {
        correrMora($this, 10);
        $sub = suscripcion($this->co->tenant->id);
        $admin = adminPlataforma();

        $this->as($admin)->postJson("/api/admin/billing/overdue/{$sub->id}/extend")->assertOk();

        correrMora($this, 16);
        expect(Subscriptions::state($this->co->tenant->id)['state'])->toBe('grace');

        correrMora($this, 22);
        expect(Subscriptions::state($this->co->tenant->id)['state'])->toBe('locked')
            ->and(DB::table('saas_dunning_attempts')->where('kind', 'extend')->whereNotNull('actor_id')->count())->toBe(1)
            ->and(DB::table('audit_events')->where('action', 'subscription.dunning_action')->count())->toBe(1);
    });

    it('la consola lista los pagos vencidos y puede reintentar el cobro', function () {
        tarjetaGuardada(suscripcion($this->co->tenant->id));
        $this->estado['declinar'] = true;
        correrMora($this, 2);
        $admin = adminPlataforma();

        $lista = $this->as($admin)->getJson('/api/admin/billing/overdue')->assertOk()->json('overdue');
        expect($lista)->toHaveCount(1)
            ->and($lista[0]['day'])->toBe(2)
            ->and($lista[0]['last_failure'])->toBe('Tu tarjeta no tiene fondos suficientes.');

        $this->estado['declinar'] = false;
        $this->postJson("/api/admin/billing/overdue/{$lista[0]['subscription_id']}/retry")->assertOk();

        expect(Subscriptions::state($this->co->tenant->id)['state'])->toBe('active')
            ->and($this->as($admin)->getJson('/api/admin/billing/overdue')->json('overdue'))->toBeEmpty();
    });

    it('una empresa no puede usar las acciones de la consola', function () {
        correrMora($this, 3);
        $sub = suscripcion($this->co->tenant->id);

        $this->as($this->co->owner)->postJson("/api/admin/billing/overdue/{$sub->id}/extend")->assertForbidden();
    });

    it('la tarjeta simulada sin fondos sirve para probar la mora en las pruebas del sistema', function () {
        DB::table('platform_integrations')->delete();
        Tests\TestCase::forgetCaches();
        tarjetaGuardada(suscripcion($this->co->tenant->id), 'simulated', '0002');

        correrMora($this, 0);

        expect(DB::table('saas_dunning_attempts')->where('kind', 'retry')->value('result'))->toBe('failed')
            ->and(Subscriptions::state($this->co->tenant->id)['state'])->toBe('grace');
    });
});

describe('transferencia solo en el plan anual', function () {
    beforeEach(function () {
        PlanTransfers::save(['enabled' => true, 'review_text' => '1 día hábil', 'accounts' => [['id' => 'bcp', 'type' => 'bank', 'bank' => 'BCP', 'holder' => 'Plataforma', 'account_number' => '123', 'currency' => 'PEN']]]);
        $this->co = $this->company();
        $this->voucher = 'data:image/png;base64,' . base64_encode('imagen');
    });

    it('rechaza la transferencia de un plan mensual', function () {
        $this->as($this->co->owner)->postJson('/api/billing/transfer', ['billing_cycle' => 'monthly', 'method_id' => 'bcp', 'operation_number' => '999', 'paid_on' => now()->toDateString(), 'voucher' => $this->voucher])
            ->assertStatus(422)->assertJsonFragment(['method_id' => [PlanTransfers::MONTHLY_BLOCKED]]);
    });

    it('acepta la transferencia del plan anual', function () {
        $this->as($this->co->owner)->postJson('/api/billing/transfer', ['billing_cycle' => 'yearly', 'method_id' => 'bcp', 'operation_number' => '999', 'paid_on' => now()->toDateString(), 'voucher' => $this->voucher])
            ->assertOk();
    });
});

describe('Culqi para los links de pago de cada empresa', function () {
    beforeEach(function () {
        $this->co = $this->company();
        conectarCulqiEmpresa($this->co->tenant->id);
        $this->clienta = $this->customer($this->co->tenant);
        $servicio = $this->servicePriced($this->co->tenant, 118, 'Tratamiento con Culqi');
        $this->venta = $this->sell($this->co, [['type' => 'service', 'id' => $servicio->id]], ['customer_id' => $this->clienta->id]);
    });

    it('el cliente paga en la página con Culqi y la venta queda pagada', function () {
        $link = crearLinkCulqi($this);
        expect($link->provider)->toBe('culqi');

        $vista = $this->getJson("/api/pay/{$link->token}")->assertOk()->json();
        expect($vista['culqi']['public_key'])->toBe('pk_test_empresa')
            ->and($vista['culqi']['amount'])->toBe(11800);

        $this->postJson("/api/pay/{$link->token}/culqi", ['token' => 'tkn_test_cliente'])->assertOk()->assertJsonPath('result.status', 'paid');

        expect($this->sale($this->venta['id'])->status)->toBe('paid')
            ->and(DB::table('payments')->where('sale_id', $this->venta['id'])->value('external_reference'))->toBe('chr_test_1')
            ->and($this->estado['cobros'][0]['source_id'])->toBe('tkn_test_cliente');
    });

    it('una tarjeta rechazada deja la venta por cobrar y el link sigue esperando', function () {
        $link = crearLinkCulqi($this);
        $this->estado['declinar'] = true;

        $this->postJson("/api/pay/{$link->token}/culqi", ['token' => 'tkn_test_cliente'])->assertStatus(422);

        expect(DB::table('payment_links')->where('id', $link->id)->value('status'))->toBe('pending')
            ->and($this->sale($this->venta['id'])->status)->not->toBe('paid');
    });

    it('con Culqi y Mercado Pago conectados se usa Culqi, y si Culqi no responde pasa a Mercado Pago', function () {
        conectarMercadoPagoEmpresa($this->co->tenant->id);
        $link = crearLinkCulqi($this);
        expect($link->provider)->toBe('culqi');

        $this->estado['caido'] = true;
        $this->postJson("/api/pay/{$link->token}/culqi", ['token' => 'tkn_test_cliente'])->assertOk()
            ->assertJsonPath('result.checkout_url', 'https://www.mercadopago.test/checkout/ORD-RESPALDO');

        expect(DB::table('payment_links')->where('id', $link->id)->value('provider'))->toBe('mercadopago');
    });

    it('el aviso de Culqi de la empresa confirma el pago', function () {
        $link = crearLinkCulqi($this);
        DB::table('payment_links')->where('id', $link->id)->update(['external_id' => 'chr_test_aviso']);
        $this->estado['cargos']['chr_test_aviso'] = ['object' => 'charge', 'id' => 'chr_test_aviso', 'amount' => 11800, 'outcome' => ['type' => 'venta_exitosa']];

        $this->postJson("/api/webhooks/payments/culqi/{$this->co->tenant->id}", ['data' => json_encode(['id' => 'chr_test_aviso'])])->assertOk()->assertJsonPath('status', 'paid');

        expect($this->sale($this->venta['id'])->status)->toBe('paid');
    });
});
