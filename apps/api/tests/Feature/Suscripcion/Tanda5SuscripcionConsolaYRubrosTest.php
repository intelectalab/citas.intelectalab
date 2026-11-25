<?php

use App\Models\BusinessType;
use App\Models\Subscription;
use App\Models\SubscriptionInvoice;
use App\Models\User;
use App\Support\BusinessTemplates;
use App\Support\PublicBooking;
use App\Support\SampleData;
use App\Support\Subscriptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function t5Sub(string $tenantId): Subscription
{
    Subscriptions::forget($tenantId);

    return Subscriptions::latest($tenantId);
}

function t5Card(Subscription $subscription): void
{
    $subscription->update([
        'external_provider' => 'simulated', 'external_subscription_id' => 'sim_card',
        'card_status' => 'authorized', 'card_brand' => 'visa', 'card_last4' => '4242', 'card_authorized_at' => now(),
    ]);
    Subscriptions::forget($subscription->tenant_id);
}

function t5Admin(): User
{
    return User::forceCreate([
        'id' => (string) Str::uuid(),
        'first_name' => 'Admin', 'last_name' => 'Tanda 5', 'email' => 't5.' . Str::lower(Str::random(6)) . '@plataforma.test',
        'password_hash' => bcrypt('clave-de-prueba-123'), 'status' => 'active', 'tenant_id' => null,
    ]);
}

describe('suscripción: avisos, planes y pago anual', function () {
    it('con el pago vencido muestra el monto y los días que faltan para quedar en solo lectura', function () {
        $co = $this->company(['plan' => self::PLAN_ESENCIAL]);
        t5Sub($co->tenant->id)->update(['status' => 'active', 'ends_at' => now()->subDays(2)]);

        $billing = $this->as($co->owner)->getJson('/api/billing')->assertOk()->json('billing');

        expect($billing['state'])->toBe('grace')
            ->and((float) $billing['overdue']['amount'])->toBe(149.0)
            ->and($billing['overdue']['days_to_readonly'])->toBeGreaterThan(0)
            ->and($billing['overdue']['since'])->not->toBeNull();
    });

    it('los planes traen sus límites de la base y cuántos meses regala el pago anual', function () {
        $co = $this->company(['plan' => self::PLAN_ESENCIAL]);
        $plans = collect($this->as($co->owner)->getJson('/api/billing')->assertOk()->json('plans'));
        $esencial = $plans->firstWhere('id', self::PLAN_ESENCIAL);

        expect($esencial['free_months'])->toBe(2)
            ->and(collect($esencial['limits'])->firstWhere('key', 'appointments_month')['value'])->toBe(800)
            ->and(collect($esencial['limits'])->firstWhere('key', 'branches')['value'])->toBe(1);
    });

    it('pasar a anual ahora descuenta los días que quedan del mes y empieza un año desde hoy', function () {
        $co = $this->company(['plan' => self::PLAN_ESENCIAL]);
        $sub = t5Sub($co->tenant->id);
        $sub->update(['billing_cycle' => 'monthly', 'starts_at' => now()->subDays(20), 'ends_at' => now()->addDays(10)]);
        t5Card($sub->fresh());

        $preview = $this->as($co->owner)->postJson('/api/billing/plan/preview', ['plan_id' => self::PLAN_ESENCIAL, 'billing_cycle' => 'yearly'])->assertOk()->json();
        $credit = round(149 * $preview['credit']['days_left'] / $preview['credit']['days_period'], 2);

        expect($preview['kind'])->toBe('yearly_now')
            ->and($preview['immediate'])->toBeTrue()
            ->and((float) $preview['credit']['amount'])->toBe($credit)
            ->and((float) $preview['today'])->toBe(round(1490 - $credit, 2));

        $this->as($co->owner)->postJson('/api/billing/plan', ['plan_id' => self::PLAN_ESENCIAL, 'billing_cycle' => 'yearly'])->assertOk()
            ->assertJsonPath('message', fn ($m) => str_contains($m, 'pago anual'));

        $after = t5Sub($co->tenant->id);
        $invoice = SubscriptionInvoice::where('tenant_id', $co->tenant->id)->where('billing_reason', 'cycle_change')->first();

        expect($after->billing_cycle)->toBe('yearly')
            ->and(now()->diffInDays($after->ends_at))->toBeGreaterThan(364)
            ->and((float) $invoice->amount)->toBe(round(1490 - $credit, 2))
            ->and((float) $invoice->list_amount)->toBe(1490.0);

        $row = collect($this->as($co->owner)->getJson('/api/billing')->json('invoices'))->firstWhere('reason', 'cycle_change');
        expect((float) $row['discount'])->toBe($credit)->and($row['status_label'])->not->toBeEmpty();
    });

    it('bajar de plan en la prueba no deja si este mes ya pasó las citas del plan nuevo', function () {
        $co = $this->company(['plan' => self::PLAN_ESENCIAL, 'trial' => true]);
        DB::table('saas_plans')->where('id', '44444444-4444-4444-4444-444444444444')->update(['limits' => json_encode(['users' => 2, 'branches' => 1, 'professionals' => 1, 'appointments_month' => 0, 'whatsapp_month' => 100])]);
        $customer = $this->customer($co->tenant);
        $service = DB::table('services')->where('tenant_id', $co->tenant->id)->first();
        $pro = $this->professional($co, 'Ana Prueba');
        DB::table('appointments')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $co->tenant->id, 'code' => 'CIT-T5-1', 'branch_id' => $co->branch->id, 'customer_id' => $customer->id, 'professional_id' => $pro->id,
            'service_id' => $service->id, 'starts_at' => now()->startOfMonth()->addHours(30), 'ends_at' => now()->startOfMonth()->addHours(31), 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $preview = $this->as($co->owner)->postJson('/api/billing/plan/preview', ['plan_id' => '44444444-4444-4444-4444-444444444444', 'billing_cycle' => 'monthly'])->assertOk()->json();

        expect($preview['blocked'])->toBeTrue()
            ->and(collect($preview['exceeded'])->pluck('key')->all())->toContain('appointments_month');
    });
});

describe('novedades y recomendados', function () {
    it('el anuncio guarda su botón, puede quedar sin publicar y la página de novedades muestra también los cerrados', function () {
        $co = $this->company();
        $admin = t5Admin();

        $live = $this->as($admin)->postJson('/api/admin/announcements', ['title' => 'Nuevo reporte', 'body' => '<p>Ya puedes verlo.</p>', 'kind' => 'news', 'audience' => 'all', 'starts_on' => now('America/Lima')->format('Y-m-d'), 'link_url' => '/reports', 'link_label' => 'Ver reportes'])
            ->assertCreated()->json();
        $this->as($admin)->postJson('/api/admin/announcements', ['title' => 'Borrador', 'body' => '<p>Aún no.</p>', 'kind' => 'news', 'audience' => 'all', 'starts_on' => now('America/Lima')->format('Y-m-d'), 'published' => false])->assertCreated();
        $this->as($admin)->postJson('/api/admin/announcements', ['title' => 'Mal enlace', 'body' => '<p>x</p>', 'kind' => 'news', 'audience' => 'all', 'starts_on' => now('America/Lima')->format('Y-m-d'), 'link_url' => 'javascript:alert(1)'])->assertStatus(422);

        $feed = $this->as($co->owner)->getJson('/api/announcements')->assertOk()->json('items');
        expect(collect($feed)->pluck('title')->all())->toBe(['Nuevo reporte'])
            ->and($feed[0]['link_url'])->toBe('/reports')
            ->and($feed[0]['link_label'])->toBe('Ver reportes');

        $this->as($co->owner)->postJson("/api/announcements/{$live['id']}/dismiss")->assertOk();
        expect($this->as($co->owner)->getJson('/api/announcements')->json('items'))->toBe([])
            ->and(collect($this->as($co->owner)->getJson('/api/announcements?all=1')->json('items'))->pluck('title')->all())->toBe(['Nuevo reporte']);
    });

    it('recomienda y gana trae los números del programa y el texto para compartir', function () {
        $co = $this->company();
        $this->as(t5Admin())->putJson('/api/admin/referrals', ['enabled' => true, 'kind' => 'days', 'days' => 30, 'percent' => 20])->assertOk();

        $data = $this->as($co->owner)->getJson('/api/support/referral')->assertOk()->json();

        expect($data['stats'])->toBe(['referred' => 0, 'subscribed' => 0, 'days' => 0, 'discounts' => 0])
            ->and($data['share_text'])->toContain('Regístrate');
    });
});

describe('empresa, página de reservas y mi perfil', function () {
    it('el RUC debe empezar con 10 o 20 y el cambio queda en la auditoría; muestra correo y moneda', function () {
        $co = $this->company();

        $this->as($co->owner)->putJson('/api/company', ['name' => $co->tenant->name, 'tax_id' => '15123456789'])->assertStatus(422)->assertJsonValidationErrors('tax_id');
        $data = $this->as($co->owner)->putJson('/api/company', ['name' => $co->tenant->name, 'tax_id' => '20123456789'])->assertOk()->json();

        expect($data['company']['tax_id'])->toBe('20123456789')
            ->and($data['tenant']['contact_email'])->toBe($co->owner->email)
            ->and($data['tenant']['currency']['code'])->toBe('PEN');

        $event = DB::table('audit_events')->where('tenant_id', $co->tenant->id)->where('action', 'company.updated')->first();
        expect($event)->not->toBeNull()->and(json_decode($event->after_data, true)['tax_id'])->toBe('20123456789');
    });

    it('la página de reservas se despublica, cambia de dirección sin repetir otra y trae el código para la web y las políticas', function () {
        $co = $this->company();
        $other = $this->company();

        $page = $this->as($co->owner)->getJson('/api/booking-page')->assertOk()->json();
        expect($page['published'])->toBeTrue()->and($page['widget'])->toContain('<iframe')->and($page['policies'])->not->toBeEmpty();

        $this->as($co->owner)->putJson('/api/booking-page/publish', ['published' => false])->assertOk()->assertJsonPath('published', false);
        expect(PublicBooking::open($co->tenant->fresh(['settings', 'activeSubscription.plan'])))->toBeFalse();

        $this->as($co->owner)->putJson('/api/booking-page/slug', ['slug' => $other->tenant->slug])->assertStatus(422)->assertJsonValidationErrors('slug');
        $this->as($co->owner)->putJson('/api/booking-page/slug', ['slug' => 'Mi Salón'])->assertStatus(422);
        $this->as($co->owner)->putJson('/api/booking-page/slug', ['slug' => 'mi-salon-t5'])->assertOk()->assertJsonPath('slug', 'mi-salon-t5');

        expect(DB::table('tenants')->where('id', $co->tenant->id)->value('slug'))->toBe('mi-salon-t5')
            ->and(DB::table('audit_events')->where('tenant_id', $co->tenant->id)->where('action', 'booking_page.slug_changed')->exists())->toBeTrue();
    });

    it('mi perfil muestra rol y alcance, y la contraseña nueva pide mayúscula, minúscula y número', function () {
        $co = $this->company();

        $roleName = DB::table('user_roles')->join('roles', 'roles.id', '=', 'user_roles.role_id')->where('user_roles.user_id', $co->owner->id)->value('roles.name') ?? 'Dueño';
        $this->as($co->owner)->getJson('/api/account/profile')->assertOk()->assertJsonPath('role', $roleName)->assertJsonPath('scope', 'Toda la empresa');
        $this->as($co->owner)->putJson('/api/account/password', ['current_password' => 'clave-de-prueba-123', 'password' => 'solominusculas1', 'password_confirmation' => 'solominusculas1'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->as($co->owner)->putJson('/api/account/password', ['current_password' => 'clave-de-prueba-123', 'password' => 'Nueva-Clave-2026', 'password_confirmation' => 'Nueva-Clave-2026'])->assertOk();
    });
});

describe('consola', function () {
    it('la lista de empresas trae ingreso al mes, usuarios, citas de 30 días y fin de la prueba', function () {
        $paid = $this->company(['plan' => self::PLAN_ESENCIAL]);
        $trial = $this->company(['plan' => self::PLAN_ESENCIAL, 'trial' => true]);

        $rows = collect($this->as(t5Admin())->getJson('/api/tenants')->assertOk()->json());

        expect((float) $rows->firstWhere('id', $paid->tenant->id)['mrr'])->toBe(149.0)
            ->and($rows->firstWhere('id', $paid->tenant->id)['active_users'])->toBe(1)
            ->and($rows->firstWhere('id', $paid->tenant->id)['appointments_30d'])->toBe(0)
            ->and($rows->firstWhere('id', $trial->tenant->id)['trial_ends_on'])->not->toBeNull();
    });

    it('cancelar la suscripción pide motivo y marcar pago vencido deja la empresa en días de gracia', function () {
        $co = $this->company(['plan' => self::PLAN_ESENCIAL]);
        $admin = t5Admin();

        $this->as($admin)->postJson("/api/tenants/{$co->tenant->id}/subscription/past-due", ['reason' => 'corto'])->assertStatus(422);
        $this->as($admin)->postJson("/api/tenants/{$co->tenant->id}/subscription/past-due", ['reason' => 'El banco devolvió la transferencia'])->assertOk();
        expect(Subscriptions::state($co->tenant->fresh())['state'])->toBe('grace')
            ->and(DB::table('audit_events')->where('tenant_id', $co->tenant->id)->where('action', 'tenant.marked_past_due')->exists())->toBeTrue();

        $this->as($admin)->postJson("/api/tenants/{$co->tenant->id}/subscription/cancel")->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->as($admin)->postJson("/api/tenants/{$co->tenant->id}/subscription/cancel", ['reason' => 'La dueña cerró el negocio'])->assertOk();
        expect(t5Sub($co->tenant->id)->status)->toBe('canceled');
    });

    it('crear una empresa valida el RUC, avisa de duplicados y puede cargar datos de ejemplo', function () {
        $existing = $this->company();
        DB::table('companies')->where('tenant_id', $existing->tenant->id)->update(['tax_id' => '20111111111']);
        $admin = t5Admin();
        $base = [
            'name' => 'Salón Nuevo T5', 'timezone' => 'America/Lima', 'status' => 'active',
            'business_type_id' => BusinessType::where('slug', 'salon')->value('id'), 'plan_id' => self::PLAN_ESENCIAL,
            'legal_name' => 'Salón Nuevo SAC', 'branch_name' => 'Sede Principal',
            'admin_first_name' => 'Rosa', 'admin_last_name' => 'Prueba', 'admin_email' => 'rosa.t5@prueba.test',
        ];

        $this->as($admin)->postJson('/api/tenants', $base + ['tax_id' => '30111111111'])->assertStatus(422)->assertJsonValidationErrors('tax_id');
        $this->as($admin)->postJson('/api/tenants', $base + ['tax_id' => '20111111111'])->assertStatus(409)->assertJsonPath('code', 'DUPLICATES');

        $res = $this->as($admin)->postJson('/api/tenants', $base + ['tax_id' => '20111111111', 'confirm_duplicates' => true, 'load_sample' => true])->assertCreated()->json();
        $tenantId = $res['data']['id'];

        expect($res['sample']['customers'])->toBe(6)
            ->and(DB::table('rooms')->where('tenant_id', $tenantId)->count())->toBe(6);
    });

    it('el archivo con todos los datos trae también los comprobantes', function () {
        $co = $this->company();

        expect(collect(\App\Support\DataBackup::sheets($co->tenant))->pluck('sheet')->all())->toContain('Comprobantes');
    });

    it('el embudo lista las pruebas por vencer para llamar hoy y los cobros traen sus tarjetas', function () {
        $trial = $this->company(['plan' => self::PLAN_ESENCIAL, 'trial' => true]);
        t5Sub($trial->tenant->id)->update(['trial_ends_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)]);
        $late = $this->company(['plan' => self::PLAN_ESENCIAL]);
        t5Sub($late->tenant->id)->update(['status' => 'active', 'ends_at' => now()->subDays(2)]);
        $admin = t5Admin();

        $extras = $this->as($admin)->getJson('/api/admin/funnel/extras')->assertOk()->json();
        $row = collect($extras['expiring'])->firstWhere('tenant_id', $trial->tenant->id);
        expect($row)->not->toBeNull()->and($row['days_left'])->toBeLessThanOrEqual(3)->and($row['email'])->toBe($trial->owner->email);

        $summary = $this->as($admin)->getJson('/api/admin/billing/overdue')->assertOk()->json('summary');
        expect((float) $summary['to_collect'])->toBeGreaterThanOrEqual(149.0)->and($summary)->toHaveKeys(['readonly', 'recovered', 'recovered_count']);
    });
});

describe('plantillas de rubro', function () {
    it('los rubros del kit traen salas, paquetes y membresías y la empresa nueva los recibe', function () {
        $salon = BusinessType::where('slug', 'salon')->first();
        expect(count($salon->template['rooms']))->toBe(6)
            ->and($salon->template['packages'][0]['name'])->toBe('Pack 5 manicures')
            ->and($salon->template['memberships'][0]['name'])->toBe('Club mensual');

        $co = $this->company(['extras' => true]);
        $rooms = DB::table('rooms')->where('tenant_id', $co->tenant->id)->count();
        $links = DB::table('service_rooms')->where('tenant_id', $co->tenant->id)->count();
        $package = DB::table('package_plans')->where('tenant_id', $co->tenant->id)->first();

        expect($rooms)->toBe(6)
            ->and($links)->toBeGreaterThan(5)
            ->and($package->name)->toBe('Pack 5 manicures')
            ->and(DB::table('package_plan_items')->where('package_plan_id', $package->id)->value('sessions'))->toBe(5)
            ->and(DB::table('membership_plans')->where('tenant_id', $co->tenant->id)->value('name'))->toBe('Club mensual');
    });

    it('un servicio apagado en la plantilla llega inactivo y la plantilla vuelve a la de fábrica', function () {
        $admin = t5Admin();
        $type = BusinessType::where('slug', 'barber')->first();
        $template = $type->template;
        $template['categories'][0]['services'][0]['active'] = false;
        $template['categories'][0]['services'][0]['name'] = 'Corte cambiado';
        $this->as($admin)->putJson("/api/admin/business-types/{$type->id}", ['template' => $template])->assertOk();

        $co = $this->company(['business' => 'barber']);
        $service = DB::table('services')->where('tenant_id', $co->tenant->id)->where('name', 'Corte cambiado')->first();
        expect(DB::table('statuses')->where('id', $service->status_id)->value('slug'))->toBe('inactive');

        $this->as($admin)->getJson("/api/admin/business-types/{$type->id}")->assertOk()->assertJsonPath('has_factory', true);
        $this->as($admin)->postJson("/api/admin/business-types/{$type->id}/reset")->assertOk();
        expect(BusinessType::find($type->id)->template['categories'][0]['services'][0]['name'])->toBe('Corte clásico');
    });

    it('la empresa importa el catálogo de otro rubro sin repetir lo que ya tiene', function () {
        $co = $this->company();
        $nails = BusinessType::where('slug', 'nails')->first();
        $before = DB::table('services')->where('tenant_id', $co->tenant->id)->count();

        $preview = $this->as($co->owner)->getJson("/api/company/catalog-import?business_type_id={$nails->id}")->assertOk()->json();
        $result = $this->as($co->owner)->postJson('/api/company/catalog-import', ['business_type_id' => $nails->id])->assertOk()->json();

        expect($result['services'])->toBe($preview['new'])
            ->and($result['services'])->toBeGreaterThan(0)
            ->and(DB::table('services')->where('tenant_id', $co->tenant->id)->count())->toBe($before + $result['services']);

        $again = $this->as($co->owner)->postJson('/api/company/catalog-import', ['business_type_id' => $nails->id])->assertOk()->json();
        expect($again['services'])->toBe(0);
    });

    it('los datos de ejemplo de una peluquería de mascotas traen sus mascotas', function () {
        $co = $this->company(['business' => 'grooming']);
        $result = SampleData::create($co->tenant->fresh(), $co->owner);

        expect($result['dependents'])->toBe(6)
            ->and(DB::table('customer_dependents')->where('tenant_id', $co->tenant->id)->whereNotNull('species')->count())->toBe(6);

        SampleData::remove($co->tenant->fresh());
        expect(DB::table('customer_dependents')->where('tenant_id', $co->tenant->id)->count())->toBe(0);
    });
});
