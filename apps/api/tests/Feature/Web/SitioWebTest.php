<?php

use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Cancellations;
use App\Support\PlanCoupons;
use App\Support\Subscriptions;
use App\Support\WebLeads;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

function webAdmin(): User
{
    return User::forceCreate([
        'id' => (string) Str::uuid(),
        'first_name' => 'Admin', 'last_name' => 'Web', 'email' => 'web.' . Str::lower(Str::random(6)) . '@plataforma.test',
        'password_hash' => bcrypt('clave-de-prueba-123'), 'status' => 'active', 'tenant_id' => null,
    ]);
}

function webSignup($test, array $extra = [])
{
    TestCase::forgetCaches();

    return $test->postJson('/api/register-tenant', $extra + [
        'ruc' => '20123456789', 'company_name' => 'Spa Nuevo ' . Str::upper(Str::random(4)), 'department' => 'Lima',
        'first_name' => 'Rosa', 'last_name' => 'Quispe', 'email' => 'rosa.' . Str::lower(Str::random(6)) . '@correo.test',
        'password' => 'clave-segura-123', 'plan_id' => TestCase::PLAN_PROFESIONAL,
        'form_token' => \App\Support\SignupGuard::token(now()->subMinute()),
    ]);
}

function webCoupon($test, User $admin, array $values = [])
{
    TestCase::forgetCaches();
    $admin->unsetRelations();
    \Laravel\Sanctum\Sanctum::actingAs($admin, ['*']);

    return $test->postJson('/api/admin/web/coupons', $values + [
        'code' => 'FERIA20', 'kind' => 'percent', 'value' => 20, 'months' => 3, 'max_uses' => 10, 'active' => true,
    ]);
}

describe('Precios y páginas públicas', function () {
    it('los precios del sitio salen de los planes y cambian al editar el plan en la consola', function () {
        $site = $this->getJson('/api/web/site')->assertOk()->json();
        $pro = collect($site['plans'])->firstWhere('id', self::PLAN_PROFESIONAL);
        expect($pro['price_monthly'])->toEqual(299.0)->and($pro['price_yearly'])->toEqual(2990.0)->and($pro['is_popular'])->toBeTrue();

        $admin = webAdmin();
        $this->as($admin)->putJson('/api/saas-plans/' . self::PLAN_PROFESIONAL, ['price_monthly' => 319, 'price_yearly' => 3190])->assertOk();
        $this->as($admin)->putJson('/api/saas-plans/' . self::PLAN_ESENCIAL, ['is_popular' => true, 'tagline' => 'Para empezar'])->assertOk();

        $site = $this->getJson('/api/web/site')->json();
        $plans = collect($site['plans'])->keyBy('id');
        expect($plans[self::PLAN_PROFESIONAL]['price_monthly'])->toEqual(319.0)
            ->and($plans[self::PLAN_ESENCIAL]['is_popular'])->toBeTrue()
            ->and($plans[self::PLAN_ESENCIAL]['tagline'])->toBe('Para empezar')
            ->and($plans[self::PLAN_PROFESIONAL]['is_popular'])->toBeFalse();
    });

    it('cada rubro tiene su página con servicios de ejemplo y el genérico no se publica', function () {
        $data = $this->getJson('/api/web/rubros/dental')->assertOk()->json();
        expect($data['business_type']['name'])->toBe('Clínica dental')
            ->and($data['business_type']['vocabulary']['client'])->toBe('Paciente')
            ->and($data['business_type']['categories'])->not->toBeEmpty();

        $this->getJson('/api/web/rubros/generic')->assertNotFound();
        $this->getJson('/api/web/rubros/no-existe')->assertNotFound();
    });

    it('las páginas legales usan los datos de la plataforma', function () {
        PlatformSetting::put(['legal_name' => 'Intelecta Lab SAC', 'legal_tax_id' => '20601234567']);
        $legal = $this->getJson('/api/web/legal/privacidad')->assertOk()->json();
        expect($legal['html'])->toContain('Intelecta Lab SAC')->toContain('20601234567')->toContain('29733');
        $this->getJson('/api/web/legal/otra')->assertNotFound();
    });

    it('los ajustes del sitio se guardan desde la consola y se ven en la web sin desplegar', function () {
        $admin = webAdmin();
        $settings = $this->as($admin)->getJson('/api/admin/web/settings')->assertOk()->json('values');

        $this->as($admin)->putJson('/api/admin/web/settings', array_merge($settings, ['ga4_id' => 'G-12']))->assertUnprocessable()->assertJsonValidationErrors('ga4_id');

        $this->as($admin)->putJson('/api/admin/web/settings', array_merge($settings, [
            'pricing_title' => 'Planes para crecer', 'whatsapp' => '+51 999 888 777', 'ga4_id' => 'G-AB12CD34EF', 'meta_pixel_id' => '123456789',
        ]))->assertOk();

        $site = $this->getJson('/api/web/site')->json('site');
        expect($site['texts']['pricing_title'])->toBe('Planes para crecer')
            ->and($site['whatsapp'])->toBe('51999888777')
            ->and($site['analytics'])->toBe(['ga4_id' => 'G-AB12CD34EF', 'meta_pixel_id' => '123456789'])
            ->and(DB::table('audit_events')->where('action', 'web.settings_changed')->count())->toBe(1);
    });

    it('solo el Administrador SaaS entra a la consola del sitio', function () {
        $co = $this->company();
        $this->as($co->owner)->getJson('/api/admin/web/coupons')->assertForbidden();
        $this->as($co->owner)->getJson('/api/admin/web/leads')->assertForbidden();
    });
});

describe('Cupones de planes', function () {
    it('se crean en la consola y el registro con cupón cuenta el uso y descuenta el primer cobro', function () {
        $admin = webAdmin();
        webCoupon($this, $admin)->assertCreated();
        $this->as($admin)->postJson('/api/admin/web/coupons', ['code' => 'feria20', 'kind' => 'percent', 'value' => 10, 'months' => 1])->assertUnprocessable()->assertJsonValidationErrors('code');

        $check = $this->postJson('/api/web/coupon', ['code' => 'feria20', 'plan_id' => self::PLAN_PROFESIONAL])->assertOk()->json();
        expect($check['preview']['first_charge'])->toEqual(239.2);

        app('auth')->forgetGuards();
        webSignup($this, ['coupon' => 'feria20'])->assertCreated();

        $coupon = DB::table('saas_coupons')->where('code', 'FERIA20')->first();
        expect((int) $coupon->used)->toBe(1);

        $redemption = DB::table('saas_coupon_redemptions')->where('coupon_id', $coupon->id)->first();
        $sub = Subscription::with('plan')->find($redemption->subscription_id);
        expect($sub->status)->toBe('trialing')
            ->and(Cancellations::chargeFor($sub, $sub->plan, 'monthly'))->toEqual(239.2);

        $invoice = Subscriptions::recordPayment($sub, []);
        expect((float) $invoice->amount)->toEqual(239.2)
            ->and((int) DB::table('saas_coupon_redemptions')->where('id', $redemption->id)->value('cycles_left'))->toBe(2);

        expect(DB::table('web_leads')->where('tenant_id', $sub->tenant_id)->value('stage'))->toBe('paid');
    });

    it('un cupón vencido o agotado se rechaza y no se crea la empresa', function () {
        $admin = webAdmin();
        webCoupon($this, $admin, ['code' => 'VIEJO10', 'valid_until' => now()->subDays(2)->toDateString()])->assertCreated();
        webCoupon($this, $admin, ['code' => 'UNAVEZ', 'max_uses' => 1])->assertCreated();
        $before = DB::table('tenants')->count();

        app('auth')->forgetGuards();
        webSignup($this, ['coupon' => 'VIEJO10'])->assertUnprocessable()->assertJsonValidationErrors('coupon');
        webSignup($this, ['coupon' => 'NOEXISTE'])->assertUnprocessable()->assertJsonValidationErrors('coupon');
        expect(DB::table('tenants')->count())->toBe($before);

        webSignup($this, ['coupon' => 'UNAVEZ'])->assertCreated();
        webSignup($this, ['coupon' => 'UNAVEZ'])->assertUnprocessable()->assertJsonPath('errors.coupon.0', 'Ese cupón ya se usó todas las veces que permitía.');
        expect((int) DB::table('saas_coupons')->where('code', 'UNAVEZ')->value('used'))->toBe(1);
    });

    it('un cupón de otro plan se rechaza', function () {
        webCoupon($this, webAdmin(), ['code' => 'SOLOEMP', 'plan_ids' => [self::PLAN_EMPRESARIAL]])->assertCreated();
        app('auth')->forgetGuards();
        webSignup($this, ['coupon' => 'SOLOEMP'])->assertUnprocessable()->assertJsonValidationErrors('coupon');
    });

    it('con monto fijo y pago anual descuenta los meses del cupón una sola vez', function () {
        webCoupon($this, webAdmin(), ['code' => 'MENOS50', 'kind' => 'amount', 'value' => 50, 'months' => 2])->assertCreated();
        $co = $this->company(['plan' => self::PLAN_PROFESIONAL, 'trial' => true]);
        $sub = Subscription::with('plan')->where('tenant_id', $co->tenant->id)->first();
        $sub->update(['billing_cycle' => 'yearly']);

        $this->as($co->owner)->postJson('/api/billing/coupon', ['code' => 'menos50'])->assertOk();
        $this->as($co->owner)->postJson('/api/billing/coupon', ['code' => 'menos50'])->assertUnprocessable();

        $sub = $sub->fresh('plan');
        expect(Cancellations::chargeFor($sub, $sub->plan, 'yearly'))->toEqual(2890.0)
            ->and(Cancellations::chargeFor($sub, $sub->plan, 'monthly'))->toEqual(249.0);

        Subscriptions::recordPayment($sub, ['billing_cycle' => 'yearly']);
        $sub = $sub->fresh('plan');
        expect(PlanCoupons::active($sub))->toBeNull()
            ->and(Cancellations::chargeFor($sub, $sub->plan, 'yearly'))->toEqual(2990.0);
    });

    it('un cupón usado no se elimina, solo se desactiva', function () {
        $admin = webAdmin();
        $id = webCoupon($this, $admin)->json('item.id');
        app('auth')->forgetGuards();
        webSignup($this, ['coupon' => 'FERIA20'])->assertCreated();

        $this->as($admin)->deleteJson("/api/admin/web/coupons/{$id}")->assertUnprocessable();
        $this->as($admin)->putJson("/api/admin/web/coupons/{$id}", ['code' => 'FERIA20', 'kind' => 'percent', 'value' => 20, 'months' => 3, 'max_uses' => 10, 'active' => false])->assertOk()->assertJsonPath('item.state', 'inactive');
        $this->postJson('/api/web/coupon', ['code' => 'FERIA20'])->assertUnprocessable();
    });
});

describe('Blog', function () {
    it('un borrador no se ve ni sale en el sitemap; publicado sí, y la consola avisa lo que falta para SEO', function () {
        $admin = webAdmin();
        $draft = $this->as($admin)->postJson('/api/admin/web/posts', [
            'title' => 'Cómo reducir las inasistencias en tu salón', 'body' => '<p>Texto</p>', 'status' => 'draft',
            'seo_description' => str_repeat('a', 170), 'business_type' => 'salon',
        ])->assertCreated()->json('item');

        expect($draft['slug'])->toBe('como-reducir-las-inasistencias-en-tu-salon')
            ->and($draft['seo_warnings'])->toContain('Falta el título para buscadores.')
            ->and(collect($draft['seo_warnings'])->contains(fn ($w) => str_contains($w, '160')))->toBeTrue();

        $this->getJson('/api/web/blog/' . $draft['slug'])->assertNotFound();
        expect($this->getJson('/api/web/sitemap')->json('posts'))->toBeEmpty();

        $this->as($admin)->putJson('/api/admin/web/posts/' . $draft['id'], [
            'title' => $draft['title'], 'slug' => $draft['slug'], 'body' => '<p>Texto</p><script>x</script>', 'status' => 'published',
            'seo_title' => 'Menos inasistencias en tu salón', 'seo_description' => 'Recordatorios y adelantos que funcionan.', 'business_type' => 'salon',
        ])->assertOk();

        app('auth')->forgetGuards();
        $post = $this->getJson('/api/web/blog/' . $draft['slug'])->assertOk()->json('post');
        expect($post['body'])->not->toContain('<script>');
        expect(collect($this->getJson('/api/web/sitemap')->json('posts'))->pluck('slug')->all())->toBe([$draft['slug']]);
        expect(collect($this->getJson('/api/web/posts?rubro=salon')->json('items'))->pluck('slug')->all())->toBe([$draft['slug']]);
        expect(DB::table('audit_events')->where('action', 'web.post_published')->count())->toBe(1);
    });
});

describe('Embudo e interesados', function () {
    it('los números del embudo cuadran con visitas, interesados, pruebas y pagos', function () {
        foreach (['home', 'home', 'pricing'] as $page) {
            $this->postJson('/api/web/visit', ['page' => $page, 'utm_source' => 'Facebook'])->assertNoContent();
        }
        $this->postJson('/api/web/visit', ['page' => 'business', 'business_type' => 'dental', 'referrer' => 'https://www.google.com/search?q=x'])->assertNoContent();

        $this->postJson('/api/web/leads', ['name' => 'Luis', 'phone' => '987654321'])->assertUnprocessable()->assertJsonValidationErrors('consent');
        $this->postJson('/api/web/leads', ['name' => 'Luis', 'phone' => '987654321', 'consent' => true, 'business_type' => 'barber'])->assertCreated();

        webSignup($this, ['utm' => ['source' => 'facebook', 'campaign' => 'feria']])->assertCreated();
        $paidTenant = DB::table('web_leads')->where('source', 'registro')->value('tenant_id');
        Subscriptions::recordPayment(Subscription::with('plan')->where('tenant_id', $paidTenant)->first(), []);

        $admin = webAdmin();
        $funnel = $this->as($admin)->getJson('/api/admin/web/funnel')->assertOk()->json();
        expect(collect($funnel['steps'])->pluck('value', 'key')->all())->toBe(['visits' => 4, 'leads' => 2, 'trials' => 1, 'paid' => 1]);

        $bySource = collect($funnel['by_source'])->keyBy('key');
        expect($bySource['registro']['paid'])->toBe(1)->and($bySource['contacto']['leads'])->toBe(1);
        expect(collect($funnel['visits_by_source'])->pluck('visits', 'source')->all())->toMatchArray(['facebook' => 3, 'google' => 1]);
        expect(DB::table('web_leads')->where('source', 'registro')->value('utm'))->toContain('feria');
        expect(DB::table('web_visits')->count())->toBeLessThan(5);
    });

    it('se mueve un interesado de etapa con su nota y no se inventan pruebas', function () {
        $admin = webAdmin();
        $lead = $this->as($admin)->postJson('/api/admin/web/leads', ['name' => 'Clínica Sonrisa', 'phone' => '999111222', 'business_type' => 'dental'])->assertCreated()->json('lead');

        $this->as($admin)->putJson("/api/admin/web/leads/{$lead['id']}/stage", ['stage' => 'contacted'])->assertOk()->assertJsonPath('lead.stage', 'contacted');
        $this->as($admin)->putJson("/api/admin/web/leads/{$lead['id']}/stage", ['stage' => 'trial'])->assertUnprocessable();
        $this->as($admin)->putJson("/api/admin/web/leads/{$lead['id']}/stage", ['stage' => 'lost'])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->as($admin)->postJson("/api/admin/web/leads/{$lead['id']}/notes", ['body' => 'Llamar el lunes'])->assertOk();

        $detail = $this->as($admin)->getJson("/api/admin/web/leads/{$lead['id']}")->assertOk()->json();
        expect(collect($detail['events'])->pluck('kind')->all())->toBe(['note', 'stage', 'stage'])
            ->and(DB::table('audit_events')->whereIn('action', ['web.lead_stage', 'web.lead_note'])->count())->toBe(2);
    });

    it('el registro convierte al interesado que ya existía con el mismo correo', function () {
        $this->postJson('/api/web/leads', ['name' => 'Rosa', 'phone' => '987654321', 'email' => 'rosa.vieja@correo.test', 'consent' => true])->assertCreated();
        webSignup($this, ['email' => 'rosa.vieja@correo.test'])->assertCreated();

        expect(DB::table('web_leads')->count())->toBe(1)
            ->and(DB::table('web_leads')->value('stage'))->toBe('trial')
            ->and(DB::table('web_leads')->value('source'))->toBe('contacto');
    });
});
