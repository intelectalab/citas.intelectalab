<?php

use App\Models\User;
use App\Support\BranchHours;
use App\Support\PublicHours;
use App\Support\SignupGuard;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

function t6Admin(): User
{
    return User::forceCreate([
        'id' => (string) Str::uuid(),
        'first_name' => 'Admin', 'last_name' => 'Sitio', 'email' => 'sitio.' . Str::lower(Str::random(6)) . '@plataforma.test',
        'password_hash' => bcrypt('clave-de-prueba-123'), 'status' => 'active', 'tenant_id' => null,
    ]);
}

function t6Signup($test, array $extra = [])
{
    TestCase::forgetCaches();

    return $test->postJson('/api/register-tenant', $extra + [
        'ruc' => '20123456786', 'company_name' => 'Spa T6 ' . Str::upper(Str::random(4)), 'department' => 'Lima',
        'first_name' => 'Rosa', 'last_name' => 'Quispe', 'email' => 'rosa.t6.' . Str::lower(Str::random(6)) . '@correo.test',
        'password' => 'clave-segura-123', 'plan_id' => TestCase::PLAN_PROFESIONAL,
        'form_token' => SignupGuard::token(now()->subMinute()),
    ]);
}

beforeEach(function () {
    config(['services.turnstile.site_key' => null, 'services.turnstile.secret_key' => null]);
});

describe('portada de la reserva pública', function () {
    beforeEach(function () {
        $this->co = $this->company();
        $this->slug = DB::table('tenants')->where('id', $this->co->tenant->id)->value('slug');
        DB::table('branches')->where('id', $this->co->branch->id)->update(['address' => 'Av. Larco 123', 'district' => 'Miraflores', 'phone' => '987654321']);
    });

    it('envía el rubro, las iniciales, las sedes con su horario y cómo llegar', function () {
        BranchHours::replace($this->co->tenant->id, $this->co->branch->id, [['weekday' => 1, 'opens' => '09:00', 'closes' => '18:00']]);

        $info = $this->getJson("/api/public/booking/{$this->slug}")->assertOk()->json();
        $sede = collect($info['branches'])->firstWhere('id', $this->co->branch->id);

        expect($info['business_type'])->toBe('Salón de belleza y spa')
            ->and($info['initials'])->toMatch('/^[A-Z]{1,2}$/')
            ->and($sede['phone'])->toBe('987654321')
            ->and($sede['map_url'])->toContain('google.com/maps')
            ->and($sede['hours'])->toHaveKey('1')
            ->and($sede['state'])->toHaveKeys(['open', 'text']);
    });

    it('dice abierto ahora o cuándo abre según el horario de la sede', function () {
        BranchHours::replace($this->co->tenant->id, $this->co->branch->id, [
            ['weekday' => 1, 'opens' => '09:00', 'closes' => '18:00'],
            ['weekday' => 2, 'opens' => '10:00', 'closes' => '14:00'],
        ]);
        $branch = $this->co->branch->fresh();
        $lunes = Carbon::parse('2026-11-23 10:30', 'America/Lima');

        expect(PublicHours::state($this->co->tenant, $branch, $lunes))->toBe(['open' => true, 'text' => 'Abierto ahora · cierra a las 18:00'])
            ->and(PublicHours::state($this->co->tenant, $branch, Carbon::parse('2026-11-23 19:00', 'America/Lima'))['text'])->toBe('Cerrado · abre mañana a las 10:00')
            ->and(PublicHours::state($this->co->tenant, $branch, Carbon::parse('2026-11-23 07:00', 'America/Lima'))['text'])->toBe('Cerrado · abre hoy a las 09:00')
            ->and(PublicHours::state($this->co->tenant, $branch, Carbon::parse('2026-11-24 15:00', 'America/Lima'))['text'])->toBe('Cerrado · abre el lunes a las 09:00');
    });

    it('sin horario de sede no inventa un estado', function () {
        expect(PublicHours::state($this->co->tenant, $this->co->branch->fresh()))->toBeNull();
    });

    it('la semana dice cuántos horarios libres hay, lleno o cerrado', function () {
        $servicio = $this->servicePriced($this->co->tenant, 50, 'Corte');
        DB::table('services')->where('id', $servicio->id)->update(['duration_minutes' => 60, 'online_booking' => true]);
        $this->professional($this->co, 'Ana Torres');
        $dia = $this->workday($this->co->tenant);

        $res = $this->getJson("/api/public/booking/{$this->slug}/days?service_id={$servicio->id}&branch_id={$this->co->branch->id}&from={$dia->toDateString()}&days=7")->assertOk()->json('days');

        expect($res)->toHaveCount(7)
            ->and($res[0]['state'])->toBe('free')
            ->and($res[0]['free'])->toBeGreaterThan(0)
            ->and(collect($res)->pluck('state')->unique()->diff(['free', 'full', 'closed', 'out'])->all())->toBe([]);
    });
});

describe('portal del cliente', function () {
    beforeEach(function () {
        $this->co = $this->company();
        $this->clienta = $this->customer($this->co->tenant, 'Lucía', 'Rojas');
    });

    it('guarda nacimiento, distrito y el permiso para SMS', function () {
        $this->as($this->clienta)->putJson('/api/portal/profile', ['birth_date' => '1990-05-12', 'district' => 'Surco'])
            ->assertOk()->assertJsonPath('district', 'Surco')->assertJsonPath('birth_date', '1990-05-12');

        $this->as($this->clienta)->putJson('/api/portal/profile', ['birth_date' => now()->addDay()->toDateString()])->assertStatus(422);

        $this->as($this->clienta)->putJson('/api/portal/consents', ['sms' => true])->assertOk()->assertJsonPath('consents.sms.granted', true);
        expect(DB::table('customer_consents')->where('customer_id', $this->clienta->id)->where('channel', 'sms')->value('granted'))->toBeTrue();

        $over = $this->as($this->clienta)->getJson('/api/portal/overview')->assertOk()->json();
        expect($over['customer']['district'])->toBe('Surco')->and($over['consents']['sms']['granted'])->toBeTrue();
    });

    it('el celular sigue siendo obligatorio cuando solo se cambia el celular', function () {
        $this->as($this->clienta)->putJson('/api/portal/profile', [])->assertStatus(422)->assertJsonValidationErrors('phone');
    });

    it('envía las políticas de cambios y cargos para mostrarlas antes de confirmar', function () {
        $this->setRules($this->co->tenant, ['reschedule.max_times' => 3]);
        $over = $this->as($this->clienta)->getJson('/api/portal/overview')->assertOk()->json('policies');

        expect($over)->toHaveKeys(['reschedule_max', 'late_fee_percent', 'noshow_fee_percent', 'cancel_reason_required'])
            ->and($over['reschedule_max'])->toBe(3);
    });

    it('resume los beneficios y el saldo pendiente', function () {
        $res = $this->as($this->clienta)->getJson('/api/portal/benefits')->assertOk()->json();

        expect($res)->toHaveKey('summary')
            ->and($res['summary'])->toHaveKeys(['points', 'gift_balance', 'balance_due', 'memberships', 'packages'])
            ->and($res['summary']['balance_due'])->toEqual(0);
    });

    it('lista las mascotas con sus controles pendientes', function () {
        $vet = $this->company(['business' => 'vet']);
        $tutor = $this->customer($vet->tenant, 'Pedro', 'Díaz');
        $firulais = $tutor->dependents()->create(['tenant_id' => $vet->tenant->id, 'name' => 'Firulais', 'kind' => 'pet', 'species' => 'Perro', 'status' => 'active']);
        $servicio = $this->servicePriced($vet->tenant, 40, 'Vacuna');
        DB::table('recalls')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $vet->tenant->id, 'customer_id' => $tutor->id, 'customer_dependent_id' => $firulais->id,
            'service_id' => $servicio->id, 'label' => 'Refuerzo de vacuna', 'due_date' => now()->addDays(10)->toDateString(), 'status' => 'pending',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $items = $this->as($tutor)->getJson('/api/portal/dependents')->assertOk()->json('items');

        expect($items)->toHaveCount(1)
            ->and($items[0]['name'])->toBe('Firulais')
            ->and($items[0]['controls'][0]['label'])->toBe('Refuerzo de vacuna')
            ->and($items[0]['controls'][0]['overdue'])->toBeFalse();
    });
});

describe('enlace para redes', function () {
    beforeEach(function () {
        $this->co = $this->company();
        $this->slug = DB::table('tenants')->where('id', $this->co->tenant->id)->value('slug');
        $this->as($this->co->owner)->putJson('/api/link-page', ['enabled' => true, 'title' => 'Mi salón', 'show_promotions' => true])->assertOk();
    });

    it('muestra las promociones, los datos del negocio y cuenta las visitas', function () {
        $info = $this->getJson("/api/public/link/{$this->slug}")->assertOk()->json();
        expect($info)->toHaveKeys(['promotions', 'gift_cards', 'business_type', 'phone']);

        RateLimiter::clear('link-visit');
        $this->postJson("/api/public/link/{$this->slug}/visit")->assertNoContent();
        $this->postJson("/api/public/link/{$this->slug}/visit")->assertNoContent();
        $this->postJson("/api/public/link/{$this->slug}/visit", ['kind' => 'booking'])->assertNoContent();

        $visits = $this->as($this->co->owner)->getJson('/api/link-page')->assertOk()->json('visits');
        expect($visits['last_30'])->toBe(2)->and($visits['bookings_30'])->toBe(1);
    });

    it('si el enlace está apagado no cuenta visitas', function () {
        $this->as($this->co->owner)->putJson('/api/link-page', ['enabled' => false])->assertOk();
        $this->postJson("/api/public/link/{$this->slug}/visit")->assertNotFound();
    });
});

describe('sitio comercial', function () {
    it('el contenido de venta se edita en la consola y queda oculto mientras está vacío', function () {
        $site = $this->getJson('/api/web/site')->assertOk()->json('site.content');
        expect($site['promo'])->toBeNull()->and($site['video'])->toBeNull()->and($site['testimonials'])->toBe([])
            ->and($site['results'])->toBe([])->and($site['partners'])->toBeNull()->and($site['popup'])->toBeNull();

        $admin = t6Admin();
        $this->as($admin)->putJson('/api/admin/web/settings', [
            'rubros_title' => 'Hecho para tu rubro', 'pricing_title' => 'Precios', 'cta_title' => 'Prueba gratis', 'cookie_text' => 'Usamos cookies.',
            'promo_enabled' => true, 'promo_text' => 'Feria de noviembre', 'promo_coupon' => 'feria20',
            'video_url' => 'https://www.youtube.com/watch?v=abc', 'video_chapters' => [['time' => '0:00', 'label' => 'Agenda'], ['time' => '1:20', 'label' => 'Caja']],
            'testimonials' => [['text' => 'Nos ordenó la agenda.', 'name' => 'Carla', 'business' => 'Salón Carla', 'rubro' => 'salon']],
            'results' => [['value' => '40 %', 'label' => 'menos inasistencias']],
            'trust_badges' => [['label' => 'Pagos seguros']],
            'popup_enabled' => true, 'popup_title' => '¿Te llamamos?', 'popup_delay' => 15,
        ])->assertOk();

        $content = $this->getJson('/api/web/site')->json('site.content');
        expect($content['promo'])->toBe(['text' => 'Feria de noviembre', 'coupon' => 'FERIA20'])
            ->and($content['video']['chapters'])->toHaveCount(2)
            ->and($content['testimonials'][0]['name'])->toBe('Carla')
            ->and($content['results'][0]['value'])->toBe('40 %')
            ->and($content['popup']['delay'])->toBe(15);

        $rubro = $this->getJson('/api/web/rubros/salon')->assertOk()->json();
        expect($rubro['testimonials'])->toHaveCount(1)
            ->and($rubro['business_type']['content']['h1'])->toContain('salón')
            ->and($rubro['business_type']['content']['plan'])->toBe('esencial')
            ->and($rubro['business_type']['group'])->toBe('Belleza y cuidado personal');
        expect($this->getJson('/api/web/rubros/dental')->json('testimonials'))->toBe([]);
    });

    it('rechaza un video que no es un enlace seguro', function () {
        $this->as(t6Admin())->putJson('/api/admin/web/settings', [
            'rubros_title' => 'x', 'pricing_title' => 'x', 'cta_title' => 'x', 'cookie_text' => 'x', 'video_url' => 'javascript:alert(1)',
        ])->assertStatus(422)->assertJsonValidationErrors('video_url');
    });

    it('la página del sitio trae los complementos activos, los grupos de rubros y las opciones de cómo nos conociste', function () {
        $site = $this->getJson('/api/web/site')->assertOk()->json();
        expect($site)->toHaveKeys(['addons', 'rubro_groups', 'heard_from'])
            ->and(collect($site['rubro_groups'])->pluck('name'))->toContain('Mascotas');
    });
});

describe('registro desde el sitio', function () {
    it('valida el RUC y el celular', function () {
        t6Signup($this, ['ruc' => '12345678901'])->assertStatus(422)->assertJsonValidationErrors('ruc');
        t6Signup($this, ['phone' => '12345'])->assertStatus(422)->assertJsonValidationErrors('phone');
        t6Signup($this, ['accept_terms' => false])->assertStatus(422)->assertJsonValidationErrors('accept_terms');
    });

    it('guarda cómo nos conoció y el artículo del que vino', function () {
        $email = 'nueva.t6.' . Str::lower(Str::random(5)) . '@correo.test';
        t6Signup($this, ['email' => $email, 'phone' => '987 654 321', 'heard_from' => 'tiktok', 'accept_terms' => true, 'utm' => ['source' => 'blog', 'post' => 'como-reducir-inasistencias']])->assertCreated();

        $lead = DB::table('web_leads')->where('email', $email)->first();
        expect($lead->heard_from)->toBe('tiktok')
            ->and(json_decode($lead->utm, true)['post'])->toBe('como-reducir-inasistencias');
    });

    it('carga los datos de ejemplo si se pide', function () {
        $email = 'ejemplo.t6.' . Str::lower(Str::random(5)) . '@correo.test';
        t6Signup($this, ['email' => $email, 'sample_data' => true, 'accept_terms' => true])->assertCreated();
        $tenant = DB::table('users')->where('email', $email)->value('tenant_id');

        if (\App\Support\SampleData::ready()) {
            expect(DB::table('customers')->where('tenant_id', $tenant)->count())->toBeGreaterThan(0);
        } else {
            expect($tenant)->not->toBeNull();
        }
    });
});

describe('blog y chat del sitio', function () {
    it('cuenta las visitas del artículo, pide palabra clave y suma registros por artículo', function () {
        $admin = t6Admin();
        $post = $this->as($admin)->postJson('/api/admin/web/posts', [
            'title' => 'Cómo reducir inasistencias en tu salón', 'body' => '<p>Texto corto.</p>', 'status' => 'published', 'keyword' => 'Inasistencias',
        ])->assertCreated()->json('item');

        expect($post['keyword'])->toBe('inasistencias')
            ->and(collect($post['seo_warnings'])->contains(fn ($w) => str_contains($w, 'subtítulos')))->toBeTrue()
            ->and(collect($post['seo_warnings'])->contains(fn ($w) => str_contains($w, 'corto')))->toBeTrue();

        $this->postJson('/api/web/visit', ['page' => 'post', 'post' => $post['slug']])->assertNoContent();
        $this->postJson('/api/web/visit', ['page' => 'post', 'post' => $post['slug']])->assertNoContent();
        t6Signup($this, ['utm' => ['post' => $post['slug']]])->assertCreated();

        $row = collect($this->as($admin)->getJson('/api/admin/web/posts')->assertOk()->json('items'))->firstWhere('id', $post['id']);
        expect($row['views'])->toBe(2)->and($row['signups'])->toBe(1);
    });

    it('guarda la conversación del chat junto con el contacto', function () {
        $this->postJson('/api/web/chat', [
            'name' => 'Mario', 'phone' => '999888777', 'consent' => true,
            'transcript' => [['from' => 'bot', 'text' => 'Hola'], ['from' => 'user', 'text' => '¿Emiten boletas?']],
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertCreated();

        $lead = DB::table('web_leads')->where('name', 'Mario')->first();
        $chat = DB::table('web_lead_events')->where('lead_id', $lead->id)->where('kind', 'chat')->value('body');
        expect($chat)->toContain('Visitante: ¿Emiten boletas?');
    });
});
