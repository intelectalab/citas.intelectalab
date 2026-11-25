<?php

use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Support\PlanChanges;
use App\Support\PlanInvoices;
use App\Support\SaasEvents;
use App\Support\Subscriptions;
use App\Support\TenantDeletion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function cprAdmin(): User
{
    return User::forceCreate([
        'id' => (string) Str::uuid(),
        'first_name' => 'Admin', 'last_name' => 'Consola', 'email' => 'cpr.' . Str::lower(Str::random(6)) . '@plataforma.test',
        'password_hash' => bcrypt('clave-de-prueba-123'), 'status' => 'active', 'tenant_id' => null,
    ]);
}

function cprSub(string $tenantId): Subscription
{
    Subscriptions::forget($tenantId);

    return Subscriptions::latest($tenantId);
}

function cprAddon(string $tenantId, string $code, int $quantity = 1): void
{
    $addon = DB::table('saas_addons')->where('code', $code)->first();
    DB::table('saas_addons')->where('id', $addon->id)->update(['status' => 'active']);
    DB::table('tenant_addons')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'addon_id' => $addon->id, 'quantity' => $quantity,
        'monthly_price' => $addon->monthly_price, 'status' => 'active', 'since' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

function cprExpire(string $tenantId, string $status = 'past_due'): void
{
    DB::table('subscriptions')->where('tenant_id', $tenantId)->update(['status' => $status, 'ends_at' => now()->subDays(40), 'trial_ends_at' => null, 'canceled_at' => $status === 'canceled' ? now()->subDays(10) : null]);
    Subscriptions::forget($tenantId);
}

describe('borrar una empresa desde la consola (A7)', function () {
    it('sin motivo o sin escribir el nombre no borra nada', function () {
        $co = $this->company(['plan' => self::PLAN_ESENCIAL]);
        $admin = cprAdmin();

        $this->as($admin)->deleteJson("/api/tenants/{$co->tenant->id}")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->as($admin)->deleteJson("/api/tenants/{$co->tenant->id}", ['reason' => 'Lo pidió la dueña por correo', 'confirm_name' => 'otro nombre'])
            ->assertStatus(422)->assertJsonValidationErrors('confirm_name');

        $tenant = DB::table('tenants')->where('id', $co->tenant->id)->first();
        expect($tenant)->not->toBeNull()->and($tenant->status)->toBe('active')->and($tenant->delete_on)->toBeNull();
    });

    it('con motivo y nombre la desactiva, cancela el plan, cierra sesiones y programa el borrado a 90 días con registro en la bitácora', function () {
        $co = $this->company(['plan' => self::PLAN_ESENCIAL]);
        $admin = cprAdmin();
        $co->owner->createToken('web');
        $this->customer($co->tenant);

        $res = $this->as($admin)->deleteJson("/api/tenants/{$co->tenant->id}", ['reason' => 'Lo pidió la dueña por correo', 'confirm_name' => mb_strtoupper($co->tenant->name)])
            ->assertOk()->assertJsonPath('status', 'inactive')->assertJsonPath('deletion.days_left', 90);

        expect($res->json('message'))->toContain('se borran el');

        $tenant = DB::table('tenants')->where('id', $co->tenant->id)->first();
        expect($tenant->deleted_at)->toBeNull()
            ->and(now()->diffInDays($tenant->delete_on))->toBeGreaterThan(89.9)
            ->and($tenant->deletion_reason)->toBe('Lo pidió la dueña por correo')
            ->and(cprSub($co->tenant->id)->status)->toBe('canceled')
            ->and(DB::table('personal_access_tokens')->where('tokenable_id', $co->owner->id)->count())->toBe(0)
            ->and(DB::table('customers')->where('tenant_id', $co->tenant->id)->count())->toBeGreaterThan(0);

        $log = DB::table('platform_audit_logs')->where('tenant_id', $co->tenant->id)->where('action', 'tenant.deletion_scheduled')->first();
        expect($log)->not->toBeNull()->and($log->code)->toStartWith('PLT-')->and(json_decode($log->after_data, true)['reason'])->toBe('Lo pidió la dueña por correo');

        $this->as($admin)->deleteJson("/api/tenants/{$co->tenant->id}", ['reason' => 'Otra vez el mismo pedido', 'confirm_name' => $co->tenant->name])->assertStatus(422);
        $this->as($admin)->postJson("/api/tenants/{$co->tenant->id}/reactivate", ['reason' => 'Quiero volver a abrirla'])->assertStatus(422);
        $this->as($admin)->putJson("/api/tenants/{$co->tenant->id}/plan", ['plan_id' => self::PLAN_PROFESIONAL, 'reactivate' => true, 'reason' => 'Prueba de reactivación'])->assertStatus(422);
    });

    it('se puede anular con motivo dentro del plazo y queda en la bitácora', function () {
        $co = $this->company(['plan' => self::PLAN_ESENCIAL]);
        $admin = cprAdmin();
        $this->as($admin)->deleteJson("/api/tenants/{$co->tenant->id}", ['reason' => 'Lo pidió la dueña por correo', 'confirm_name' => $co->tenant->name])->assertOk();

        $this->as($admin)->postJson("/api/tenants/{$co->tenant->id}/deletion/cancel", ['reason' => 'corto'])->assertStatus(422);
        $this->as($admin)->postJson("/api/tenants/{$co->tenant->id}/deletion/cancel", ['reason' => 'La dueña se arrepintió y llamó'])
            ->assertOk()->assertJsonPath('deletion', null);

        expect(DB::table('tenants')->where('id', $co->tenant->id)->value('delete_on'))->toBeNull()
            ->and(DB::table('platform_audit_logs')->where('tenant_id', $co->tenant->id)->where('action', 'tenant.deletion_canceled')->exists())->toBeTrue();

        $this->as($admin)->postJson("/api/tenants/{$co->tenant->id}/reactivate", ['reason' => 'Vuelve a trabajar con nosotros'])->assertOk()->assertJsonPath('status', 'active');
    });

    it('al cumplirse el plazo tenants:retention borra los datos personales y la oculta, aunque el borrado automático esté apagado', function () {
        $co = $this->company(['plan' => self::PLAN_ESENCIAL]);
        $otra = $this->company(['plan' => self::PLAN_ESENCIAL]);
        $customer = $this->customer($co->tenant, 'Lucía', 'Quispe');
        $admin = cprAdmin();
        $this->as($admin)->deleteJson("/api/tenants/{$co->tenant->id}", ['reason' => 'Lo pidió la dueña por correo', 'confirm_name' => $co->tenant->name])->assertOk();

        Artisan::call('tenants:retention');
        expect(DB::table('tenants')->where('id', $co->tenant->id)->value('deleted_at'))->toBeNull();

        DB::table('tenants')->where('id', $co->tenant->id)->update(['delete_on' => now()->subMinute()]);
        Artisan::call('tenants:retention');

        $tenant = DB::table('tenants')->where('id', $co->tenant->id)->first();
        expect($tenant->deleted_at)->not->toBeNull()
            ->and($tenant->data_purged_at)->not->toBeNull()
            ->and(DB::table('customers')->where('id', $customer->id)->value('first_name'))->not->toBe('Lucía')
            ->and(DB::table('users')->where('id', $co->owner->id)->value('email'))->toEndWith('@borrado.invalid')
            ->and(DB::table('tenants')->where('id', $otra->tenant->id)->value('deleted_at'))->toBeNull()
            ->and(DB::table('platform_audit_logs')->where('tenant_id', $co->tenant->id)->where('action', 'tenant.deleted_after_term')->exists())->toBeTrue();

        $ids = collect($this->as($admin)->getJson('/api/tenants')->assertOk()->json())->pluck('id');
        expect($ids)->not->toContain($co->tenant->id)->and($ids)->toContain($otra->tenant->id);
    });
});

describe('cambiar el plan desde la consola (A8)', function () {
    it('no deja pasar a un plan donde no cabe lo que usa la empresa', function () {
        $co = $this->company(['plan' => self::PLAN_PROFESIONAL]);
        $this->branch($co, 'Sede Norte');
        $admin = cprAdmin();

        $res = $this->as($admin)->putJson("/api/tenants/{$co->tenant->id}/plan", ['plan_id' => self::PLAN_ESENCIAL])->assertStatus(422)->assertJsonPath('code', 'PLAN_LIMIT');
        expect($res->json('message'))->toContain('Sedes')->and(cprSub($co->tenant->id)->plan_id)->toBe(self::PLAN_PROFESIONAL);
    });

    it('cuenta los complementos contratados al revisar si cabe', function () {
        $co = $this->company(['plan' => self::PLAN_PROFESIONAL]);
        $this->branch($co, 'Sede Norte');
        cprAddon($co->tenant->id, 'sede-adicional');

        $this->as(cprAdmin())->putJson("/api/tenants/{$co->tenant->id}/plan", ['plan_id' => self::PLAN_ESENCIAL])->assertOk();
        expect(cprSub($co->tenant->id)->plan_id)->toBe(self::PLAN_ESENCIAL);
    });

    it('con la suscripción vigente cambia el plan y conserva las fechas', function () {
        $co = $this->company(['plan' => self::PLAN_ESENCIAL]);
        $before = cprSub($co->tenant->id);

        $this->as(cprAdmin())->putJson("/api/tenants/{$co->tenant->id}/plan", ['plan_id' => self::PLAN_PROFESIONAL])->assertOk();
        $after = cprSub($co->tenant->id);
        expect($after->plan_id)->toBe(self::PLAN_PROFESIONAL)->and($after->ends_at->toIso8601String())->toBe($before->ends_at->toIso8601String());
    });

    it('vencida: no la reactiva sin pedirlo; con «reactivar sin cobrar» exige motivo y lo deja en la bitácora', function () {
        $co = $this->company(['plan' => self::PLAN_ESENCIAL]);
        $admin = cprAdmin();
        cprExpire($co->tenant->id);

        $this->as($admin)->putJson("/api/tenants/{$co->tenant->id}/plan", ['plan_id' => self::PLAN_PROFESIONAL])->assertStatus(422)->assertJsonPath('code', 'NEEDS_REACTIVATION');
        expect(cprSub($co->tenant->id)->status)->toBe('past_due')->and(cprSub($co->tenant->id)->plan_id)->toBe(self::PLAN_ESENCIAL);

        $this->as($admin)->putJson("/api/tenants/{$co->tenant->id}/plan", ['plan_id' => self::PLAN_PROFESIONAL, 'reactivate' => true, 'reason' => 'poco'])->assertStatus(422)->assertJsonValidationErrors('reason');
        expect(cprSub($co->tenant->id)->status)->toBe('past_due');

        $this->as($admin)->putJson("/api/tenants/{$co->tenant->id}/plan", ['plan_id' => self::PLAN_PROFESIONAL, 'reactivate' => true, 'reason' => 'Pagó por transferencia y llega mañana'])->assertOk();
        $sub = cprSub($co->tenant->id);
        expect($sub->status)->toBe('active')->and($sub->plan_id)->toBe(self::PLAN_PROFESIONAL)->and($sub->ends_at->isFuture())->toBeTrue()
            ->and(SubscriptionInvoice::where('subscription_id', $sub->id)->count())->toBe(0);

        $log = DB::table('platform_audit_logs')->where('tenant_id', $co->tenant->id)->where('action', 'tenant.plan_changed')->orderByDesc('number')->first();
        $after = json_decode($log->after_data, true);
        expect($after['reactivated_without_payment'])->toBeTrue()->and($after['reason'])->toBe('Pagó por transferencia y llega mañana');
    });
});

describe('monto real cobrado (M8)', function () {
    it('la pantalla del plan muestra lo que se cobra: descuento y complementos, no el precio de lista', function () {
        $co = $this->company(['plan' => self::PLAN_ESENCIAL]);
        DB::table('subscriptions')->where('tenant_id', $co->tenant->id)->update(['discount_percent' => 50, 'discount_cycles' => 2]);
        cprAddon($co->tenant->id, 'usuario-adicional');

        $billing = $this->as($co->owner)->getJson('/api/billing')->assertOk()->json('billing');
        expect(money($billing['price']))->toBe(149.0)->and(money($billing['charge']))->toBe(74.5 + 15.0);

        $detail = $this->as(cprAdmin())->getJson("/api/tenants/{$co->tenant->id}")->assertOk()->json('subscription');
        expect(money($detail['charge']))->toBe(89.5)->and(money($detail['mrr']))->toBe(89.5)->and(money($detail['list_price']))->toBe(149.0);
    });

    it('el MRR de la tarjeta, del gráfico y de los movimientos es el mismo y usa el monto real', function () {
        $a = $this->company(['plan' => self::PLAN_ESENCIAL]);
        $b = $this->company(['plan' => self::PLAN_PROFESIONAL]);
        DB::table('subscriptions')->where('tenant_id', $b->tenant->id)->update(['billing_cycle' => 'yearly']);
        DB::table('subscriptions')->where('tenant_id', $a->tenant->id)->update(['discount_percent' => 50, 'discount_cycles' => 2]);
        cprAddon($a->tenant->id, 'usuario-adicional');
        Subscriptions::forget();
        SaasEvents::reconcile();

        $expected = round(89.5 + 2990 / 12, 2);
        $admin = cprAdmin();
        $summary = $this->as($admin)->getJson('/api/admin/reports/summary')->assertOk()->json();
        $data = $this->as($admin)->getJson('/api/admin/reports?tab=metrics')->assertOk()->json('data');

        expect(money($summary['mrr']['amount']))->toBe($expected)
            ->and(money($data['summary']['mrr']['amount']))->toBe($expected)
            ->and(money(end($data['by_month'])['mrr']))->toBe($expected)
            ->and(money(SaasEvents::bookedMrr()))->toBe($expected)
            ->and(money(collect($data['mrr_by_plan'])->sum('value')))->toBe($expected);
    });

    it('reconcile anota el cambio cuando el monto real cambió sin evento', function () {
        $co = $this->company(['plan' => self::PLAN_ESENCIAL]);
        SaasEvents::reconcile();
        $before = SaasEvents::bookedMrr();
        DB::table('subscriptions')->where('tenant_id', $co->tenant->id)->update(['discount_percent' => 50, 'discount_cycles' => 1]);

        expect(SaasEvents::reconcile())->toBe(1)->and(money(SaasEvents::bookedMrr()))->toBe(money($before - 74.5))
            ->and(SaasEvents::reconcile())->toBe(0);
    });
});

describe('gravedad baja', function () {
    it('subir de plan calcula la diferencia con el descuento de la empresa', function () {
        $co = $this->company(['plan' => self::PLAN_ESENCIAL]);
        DB::table('subscriptions')->where('tenant_id', $co->tenant->id)->update(['discount_percent' => 50, 'discount_cycles' => 2, 'starts_at' => now()->subDays(15), 'ends_at' => now()->addDays(15)]);
        $sub = cprSub($co->tenant->id);

        $preview = PlanChanges::preview($sub, \App\Models\SaaSPlan::find(self::PLAN_PROFESIONAL), 'monthly');
        $prorated = $preview['prorated'];
        $expected = round((299 * 0.5 - 149 * 0.5) * min($prorated['days_left'], $prorated['days_period']) / $prorated['days_period'], 2);

        expect($preview['kind'])->toBe('upgrade')->and(money($prorated['amount']))->toBe($expected)->and($expected)->toBeLessThan(80.0);
    });

    it('la boleta del plan no toma un segundo número si se emite dos veces', function () {
        $co = $this->company(['plan' => self::PLAN_ESENCIAL]);
        $sub = cprSub($co->tenant->id);
        $stale = SubscriptionInvoice::create([
            'subscription_id' => $sub->id, 'tenant_id' => $co->tenant->id,
            'status_id' => DB::table('statuses')->where('slug', 'paid')->whereNull('tenant_id')->value('id'),
            'amount' => 149, 'currency' => 'PEN', 'billing_reason' => 'manual', 'invoice_date' => now(), 'paid_at' => now(),
        ]);

        if (!PlanInvoices::ready()) {
            $this->markTestSkipped('Sin boletas del plan en esta base.');
        }

        $first = PlanInvoices::issue($stale);
        $next = (int) DB::table('saas_invoice_series')->where('doc_type', $first->doc_type)->where('serie', $first->serie)->value('next_number');
        $second = PlanInvoices::issue($stale);

        expect($second->number)->toBe($first->number)
            ->and((int) DB::table('saas_invoice_series')->where('doc_type', $first->doc_type)->where('serie', $first->serie)->value('next_number'))->toBe($next);
    });
});
