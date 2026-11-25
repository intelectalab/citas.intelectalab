<?php

use App\Models\Customer;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Addons;
use App\Support\DataRetention;
use App\Support\Dunning;
use App\Support\PlatformWhatsApp;
use App\Support\Subscriptions;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function comSub(string $tenantId): Subscription
{
    Subscriptions::forget($tenantId);

    return Subscriptions::latest($tenantId);
}

function comCard(Subscription $subscription): void
{
    $subscription->update([
        'external_provider' => 'simulated', 'external_subscription_id' => 'sim_card',
        'card_status' => 'authorized', 'card_brand' => 'visa', 'card_last4' => '4242', 'card_authorized_at' => now(),
    ]);
    Subscriptions::forget($subscription->tenant_id);
}

function comPlatform(): User
{
    return User::forceCreate([
        'id' => (string) Str::uuid(),
        'first_name' => 'Ana', 'last_name' => 'Plataforma', 'email' => 'com.' . Str::lower(Str::random(6)) . '@plataforma.test',
        'password_hash' => bcrypt('clave-de-prueba-123'), 'status' => 'active', 'tenant_id' => null,
    ]);
}

function comAddon(string $code): object
{
    DB::table('saas_addons')->where('code', $code)->update(['status' => 'active']);

    return DB::table('saas_addons')->where('code', $code)->first();
}

function comFresh(string $tenantId): \App\Models\Tenant
{
    Subscriptions::forget($tenantId);

    return \App\Models\Tenant::find($tenantId);
}

describe('complementos del plan', function () {
    it('vienen apagados y el Administrador SaaS los enciende, cambia y no puede borrar uno ya contratado', function () {
        expect(DB::table('saas_addons')->where('status', 'active')->count())->toBe(0)
            ->and(DB::table('saas_addons')->pluck('code')->sort()->values()->all())->toBe(['profesional-adicional', 'sede-adicional', 'usuario-adicional', 'whatsapp-500']);

        $admin = comPlatform();
        $list = $this->as($admin)->getJson('/api/admin/addons')->assertOk()->json('addons');
        $sede = collect($list)->firstWhere('code', 'sede-adicional');

        $this->as($admin)->putJson("/api/admin/addons/{$sede['id']}", ['name' => 'Sede adicional', 'monthly_price' => 45, 'limit_key' => 'branches', 'limit_amount' => 1, 'module_key' => 'multi_branch', 'max_quantity' => 5, 'status' => 'active'])
            ->assertOk()->assertJsonPath('status', 'active')->assertJsonPath('monthly_price', 45);

        $this->as($admin)->postJson('/api/admin/addons', ['name' => 'Algo raro', 'monthly_price' => 10, 'max_quantity' => 1, 'status' => 'active'])->assertStatus(422)->assertJsonValidationErrors('limit_key');
        $new = $this->as($admin)->postJson('/api/admin/addons', ['name' => 'Cien citas más', 'monthly_price' => 12, 'limit_key' => 'appointments_month', 'limit_amount' => 100, 'max_quantity' => 3, 'status' => 'active'])->assertCreated()->json();
        expect($new['code'])->toBe('cien-citas-mas');

        $co = $this->company(['plan' => self::PLAN_ESENCIAL, 'trial' => true]);
        $this->as($co->owner)->postJson('/api/billing/addons', ['addon_id' => $sede['id']])->assertOk();
        $this->as($admin)->deleteJson("/api/admin/addons/{$sede['id']}")->assertStatus(422);
        $this->as($admin)->deleteJson("/api/admin/addons/{$new['id']}")->assertOk();

        expect(DB::table('platform_audit_logs')->where('action', 'platform.addon_updated')->value('code'))->toStartWith('PLT-');
    });

    it('en la prueba se suma sin cobrar, amplía el límite y enciende su módulo, y se cobra con el plan', function () {
        $sede = comAddon('sede-adicional');
        $co = $this->company(['plan' => self::PLAN_ESENCIAL, 'trial' => true]);
        expect($co->tenant->planLimits()['branches'])->toBe(1)->and($co->tenant->hasModule('multi_branch'))->toBeFalse();

        $preview = $this->as($co->owner)->postJson('/api/billing/addons/preview', ['addon_id' => $sede->id, 'quantity' => 2])->assertOk()->json();
        expect($preview['kind'])->toBe('trial')->and((float) $preview['today'])->toBe(0.0)->and((float) $preview['next_charge'])->toBe(78.0);

        $this->as($co->owner)->postJson('/api/billing/addons', ['addon_id' => $sede->id, 'quantity' => 2])->assertOk()
            ->assertJsonPath('billing.addons.mine.0.quantity', 2)
            ->assertJsonPath('billing.charge', 149 + 78);

        $tenant = comFresh($co->tenant->id);
        expect($tenant->planLimits()['branches'])->toBe(3)->and($tenant->hasModule('multi_branch'))->toBeTrue();
        expect(Subscriptions::price(comSub($co->tenant->id)->plan, 'monthly'))->toBe(149.0);
    });

    it('con el plan pagado cobra a la tarjeta solo los días que faltan, emite su boleta y suma al próximo cobro', function () {
        $wa = comAddon('whatsapp-500');
        $co = $this->company(['plan' => self::PLAN_ESENCIAL]);
        $sub = comSub($co->tenant->id);
        $sub->update(['status' => 'active', 'starts_at' => now()->subDays(20), 'ends_at' => now()->addDays(10)]);

        $this->as($co->owner)->postJson('/api/billing/addons/preview', ['addon_id' => $wa->id])->assertOk()->assertJsonPath('blocked', true);
        $this->as($co->owner)->postJson('/api/billing/addons', ['addon_id' => $wa->id])->assertStatus(422);

        comCard(comSub($co->tenant->id));
        $preview = $this->as($co->owner)->postJson('/api/billing/addons/preview', ['addon_id' => $wa->id])->assertOk()->json();
        $expected = round(29 * $preview['days_left'] / 30, 2);
        expect($preview['kind'])->toBe('charge')->and((float) $preview['today'])->toBeGreaterThan(8.0)->toBeLessThan(12.0);

        $key = (string) Str::uuid();
        $res = $this->as($co->owner)->postJson('/api/billing/addons', ['addon_id' => $wa->id], ['Idempotency-Key' => $key])->assertOk();
        expect($res->json('message'))->toContain('por los días que faltan');
        $this->as($co->owner)->postJson('/api/billing/addons', ['addon_id' => $wa->id], ['Idempotency-Key' => $key])->assertOk();

        $invoice = DB::table('subscription_invoices')->where('tenant_id', $co->tenant->id)->where('billing_reason', 'addon')->get();
        expect($invoice)->toHaveCount(1)->and(abs((float) $invoice[0]->amount - (float) $preview['today']))->toBeLessThan(0.02)->and($expected)->toBeGreaterThan(0);
        expect(Addons::active($co->tenant->id)->first()->quantity)->toBe(1);

        $tenant = comFresh($co->tenant->id);
        expect($tenant->planLimits()['whatsapp_month'])->toBe(800)
            ->and(\App\Support\MessageQuota::limit($tenant, 'whatsapp'))->toBe(800)
            ->and(\App\Support\Cancellations::chargeFor(comSub($co->tenant->id), comSub($co->tenant->id)->plan, 'monthly'))->toBe(178.0)
            ->and(comSub($co->tenant->id)->ends_at->isFuture())->toBeTrue();
    });

    it('quitarlo lo deja hasta el fin del periodo, no se cobra más y se puede anular el retiro', function () {
        $user = comAddon('usuario-adicional');
        $co = $this->company(['plan' => self::PLAN_ESENCIAL]);
        comSub($co->tenant->id)->update(['status' => 'active', 'starts_at' => now()->subDays(5), 'ends_at' => now()->addDays(25)]);
        comCard(comSub($co->tenant->id));
        $this->as($co->owner)->postJson('/api/billing/addons', ['addon_id' => $user->id, 'quantity' => 3])->assertOk();

        $this->as($co->owner)->deleteJson("/api/billing/addons/{$user->id}")->assertOk()->assertJsonPath('message', fn ($m) => str_contains($m, 'no se devuelve'));
        $sub = comSub($co->tenant->id);
        expect(\App\Support\Cancellations::chargeFor($sub, $sub->plan, 'monthly'))->toBe(149.0)
            ->and(comFresh($co->tenant->id)->planLimits()['users'])->toBe(8);

        $this->as($co->owner)->postJson("/api/billing/addons/{$user->id}/keep")->assertOk();
        expect(\App\Support\Cancellations::chargeFor(comSub($co->tenant->id), $sub->plan, 'monthly'))->toBe(149.0 + 45.0);

        $this->as($co->owner)->deleteJson("/api/billing/addons/{$user->id}")->assertOk();
        $this->travelTo(now()->addDays(26));
        expect(Addons::expireDue())->toBe(1)
            ->and(comFresh($co->tenant->id)->planLimits()['users'])->toBe(5)
            ->and(DB::table('tenant_addons')->where('tenant_id', $co->tenant->id)->value('status'))->toBe('removed');
    });

    it('no deja pasar del máximo ni sumar con el plan vencido', function () {
        $sede = comAddon('sede-adicional');
        $co = $this->company(['plan' => self::PLAN_ESENCIAL, 'trial' => true]);
        $this->as($co->owner)->postJson('/api/billing/addons', ['addon_id' => $sede->id, 'quantity' => 11])->assertStatus(422);

        comSub($co->tenant->id)->update(['status' => 'active', 'trial_ends_at' => null, 'ends_at' => now()->subDays(20)]);
        $this->as($co->owner)->postJson('/api/billing/addons/preview', ['addon_id' => $sede->id])->assertOk()->assertJsonPath('blocked', true)
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'Primero paga'));
    });
});

describe('permiso admin.plan', function () {
    it('sin el permiso no ve montos ni pagos y no puede pagar; con el permiso sí', function () {
        $co = $this->company(['plan' => self::PLAN_ESENCIAL]);
        $recepcion = $this->staff($co, 'reception', $co->branch, 'Rosa Recepción');

        $view = $this->as($recepcion)->getJson('/api/billing')->assertOk()->json();
        expect($view['billing']['can_pay'])->toBeFalse()->and($view['invoices'])->toBe([])->and($view['billing']['card'])->toBeNull()->and($view['billing']['addons'])->toBeNull();
        $this->as($recepcion)->postJson('/api/billing/pay')->assertForbidden()->assertJsonPath('message', Subscriptions::NO_PERMISSION);
        $this->as($recepcion)->postJson('/api/billing/exports')->assertForbidden();

        $permission = DB::table('permissions')->where('name', 'admin.plan')->value('id');
        expect($permission)->not->toBeNull();
        DB::table('user_permissions')->insert(['tenant_id' => $co->tenant->id, 'user_id' => $recepcion->id, 'permission_id' => $permission, 'granted' => true]);
        self::forgetCaches();

        $this->as($recepcion->fresh())->getJson('/api/billing')->assertOk()->assertJsonPath('billing.can_pay', true);
        $this->as($co->owner)->getJson('/api/billing')->assertOk()->assertJsonPath('billing.can_pay', true);
        expect(collect(\App\Support\Access::grid($co->tenant, []))->firstWhere('key', 'admin')['actions'][0]['name'])->toBe('admin.plan');
    });
});

describe('bitácora de la plataforma', function () {
    it('cada acción del Administrador SaaS queda con su código PLT, en orden, aparte de la auditoría de las empresas', function () {
        $admin = comPlatform();
        $co = $this->company();

        $this->as($admin)->postJson("/api/tenants/{$co->tenant->id}/suspend", ['reason' => 'Pedido de prueba de la empresa'])->assertOk();
        $this->as($admin)->postJson("/api/tenants/{$co->tenant->id}/reactivate", ['reason' => 'Ya resolvió lo pendiente'])->assertOk();

        $rows = DB::table('platform_audit_logs')->where('tenant_id', $co->tenant->id)->orderBy('number')->get();
        expect($rows->pluck('action')->all())->toContain('tenant.suspended', 'tenant.reactivated')
            ->and($rows[0]->code)->toMatch('/^PLT-\d{6}$/')
            ->and((int) $rows[1]->number)->toBe((int) $rows[0]->number + 1);

        $this->as($co->owner)->putJson('/api/company', ['name' => 'Otro nombre'])->status();
        expect(DB::table('platform_audit_logs')->where('actor_user_id', $co->owner->id)->exists())->toBeFalse();

        $list = $this->as($admin)->getJson('/api/admin/audit')->assertOk()->json();
        expect($list['separate'])->toBeTrue()->and(collect($list['events'])->pluck('code')->filter()->count())->toBeGreaterThanOrEqual(2);
        $one = $this->as($admin)->getJson('/api/admin/audit?code=' . (int) $rows[0]->number)->assertOk()->json('events');
        expect($one)->toHaveCount(1)->and($one[0]['code'])->toBe($rows[0]->code);

        $this->as($co->owner)->getJson('/api/admin/audit')->assertForbidden();
        expect(fn () => DB::table('platform_audit_logs')->where('id', $rows[0]->id)->update(['action' => 'x']))->toThrow(\Illuminate\Database\QueryException::class);
        expect(fn () => DB::table('platform_audit_logs')->where('id', $rows[0]->id)->delete())->toThrow(\Illuminate\Database\QueryException::class);
    });
});

describe('aviso de mora por WhatsApp', function () {
    it('sin claves no sale nada; con WhatsApp Cloud configurado sale la plantilla a los administradores', function () {
        $co = $this->company(['plan' => self::PLAN_ESENCIAL]);
        DB::table('users')->where('id', $co->owner->id)->update(['phone' => '987654321']);
        $sub = comSub($co->tenant->id);
        $sub->update(['status' => 'active', 'ends_at' => now()->subDays(3)]);
        $sent = [];
        Http::fake(['https://graph.facebook.com/*' => function (HttpRequest $request) use (&$sent) {
            $sent[] = $request->data();

            return Http::response(['messages' => [['id' => 'wamid.' . count($sent)]]]);
        }]);

        expect(PlatformWhatsApp::enabled())->toBeFalse();
        Dunning::remind(comSub($co->tenant->id), 3);
        expect($sent)->toBe([]);

        $admin = comPlatform();
        $this->as($admin)->putJson('/api/admin/billing/whatsapp', ['provider' => 'cloud', 'phone_number_id' => '123'])->assertStatus(422)->assertJsonValidationErrors('token');
        $this->as($admin)->putJson('/api/admin/billing/whatsapp', ['provider' => 'cloud', 'token' => 'EAAtoken1234', 'phone_number_id' => '123', 'templates' => ['overdue' => 'plan_vencido']])
            ->assertOk()->assertJsonPath('enabled', true)->assertJsonPath('token_saved', '••••••••1234');
        PlatformWhatsApp::forget();

        Dunning::remind(comSub($co->tenant->id), 3);
        expect($sent)->toHaveCount(1)
            ->and($sent[0]['to'])->toBe('51987654321')
            ->and($sent[0]['type'])->toBe('template')
            ->and($sent[0]['template']['name'])->toBe('plan_vencido');
        expect(DB::table('platform_audit_logs')->where('tenant_id', $co->tenant->id)->where('action', 'subscription.overdue_whatsapp')->exists())->toBeTrue();

        PlatformWhatsApp::notify($co->tenant->id, 'canceled', [$co->tenant->name]);
        expect($sent)->toHaveCount(2)->and($sent[1]['type'])->toBe('text');
    });
});

describe('borrado de datos a los 90 días', function () {
    it('viene apagado: una empresa cancelada hace meses conserva sus datos', function () {
        $co = $this->company(['plan' => self::PLAN_ESENCIAL]);
        $this->customer($co->tenant, 'Lucía', 'Pérez');
        comSub($co->tenant->id)->update(['status' => 'canceled', 'canceled_at' => now()->subDays(200)]);

        expect(DataRetention::config()['enabled'])->toBeFalse()->and(DataRetention::run())->toBe(['avisos' => 0, 'borradas' => 0]);
        $this->artisan('tenants:retention')->assertSuccessful();
        expect(Customer::where('tenant_id', $co->tenant->id)->value('first_name'))->toBe('Lucía');
    });

    it('encendido: avisa a los 15 y 7 días con el archivo de datos, y a los 90 borra lo personal y conserva lo contable', function () {
        $admin = comPlatform();
        $this->as($admin)->putJson('/api/admin/billing/retention', ['enabled' => true, 'days' => 90, 'warn_days' => [15, 7]])->assertOk()->assertJsonPath('enabled', true);

        $co = $this->company(['plan' => self::PLAN_ESENCIAL]);
        $clienta = $this->customer($co->tenant, 'Lucía', 'Pérez');
        $otra = $this->company(['plan' => self::PLAN_ESENCIAL]);
        $this->customer($otra->tenant, 'Marta', 'Ríos');
        comSub($co->tenant->id)->update(['status' => 'canceled', 'canceled_at' => now()]);

        $this->travelTo(now()->addDays(60));
        expect(DataRetention::run()['avisos'])->toBe(0);

        $this->travelTo(now()->addDays(16));
        expect(DataRetention::run())->toBe(['avisos' => 1, 'borradas' => 0]);
        expect(DataRetention::run())->toBe(['avisos' => 0, 'borradas' => 0]);
        expect(DB::table('user_notifications')->where('tenant_id', $co->tenant->id)->where('type', 'subscription.purge_warning')->exists())->toBeTrue();

        $this->travelTo(now()->addDays(8));
        expect(DataRetention::run()['avisos'])->toBe(1);
        expect(DB::table('data_exports')->where('tenant_id', $co->tenant->id)->exists())->toBeTrue();

        $this->travelTo(now()->addDays(7));
        expect(DataRetention::run()['borradas'])->toBe(1);

        $clienta = Customer::withTrashed()->find($clienta->id);
        expect($clienta->first_name)->toBe('Cliente')->and($clienta->anonymized_at)->not->toBeNull()->and($clienta->email)->toBeNull();
        $owner = User::find($co->owner->id);
        expect($owner->status)->toBe('inactive')->and($owner->email)->toEndWith('@borrado.invalid');
        expect(DB::table('tenants')->where('id', $co->tenant->id)->value('data_purged_at'))->not->toBeNull()
            ->and(DB::table('platform_audit_logs')->where('tenant_id', $co->tenant->id)->where('action', 'tenant.data_purged')->exists())->toBeTrue()
            ->and(Customer::where('tenant_id', $otra->tenant->id)->value('first_name'))->toBe('Marta');
        expect(DataRetention::run())->toBe(['avisos' => 0, 'borradas' => 0]);
    });

    it('si se enciende tarde, primero avisa y recién borra pasado el último aviso; si vuelve a pagar no se borra', function () {
        DataRetention::save(['enabled' => true, 'days' => 90, 'warn_days' => [15, 7]]);
        $co = $this->company(['plan' => self::PLAN_ESENCIAL]);
        $this->customer($co->tenant, 'Lucía', 'Pérez');
        comSub($co->tenant->id)->update(['status' => 'canceled', 'canceled_at' => now()->subDays(200)]);

        expect(DataRetention::run())->toBe(['avisos' => 1, 'borradas' => 0]);
        $this->travelTo(now()->addDays(3));
        expect(DataRetention::run())->toBe(['avisos' => 0, 'borradas' => 0]);

        comSub($co->tenant->id)->update(['status' => 'active', 'canceled_at' => null, 'ends_at' => now()->addMonth()]);
        $this->travelTo(now()->addDays(10));
        expect(DataRetention::run())->toBe(['avisos' => 0, 'borradas' => 0])
            ->and(Customer::where('tenant_id', $co->tenant->id)->value('first_name'))->toBe('Lucía');
    });
});
