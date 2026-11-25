<?php

use App\Models\Tenant;
use App\Support\AppointmentFlow;
use App\Support\Loyalty;
use App\Support\Messaging;
use App\Support\Surveys;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

const TURNSTILE_PRUEBA = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
const NIUBIZ_PRUEBA = 'https://apisandbox.vnforappstest.com';

describe('Si Cloudflare no responde, el «no soy un robot» no bloquea', function () {
    beforeEach(function () {
        DB::table('platform_integrations')->insert([
            'id' => (string) Str::uuid(), 'kind' => 'captcha', 'provider' => 'turnstile', 'mode' => 'production', 'status' => 'active',
            'settings' => json_encode(['site_key' => '0x4AAA', 'places' => ['complaints', 'booking', 'signup']]),
            'credentials' => \Illuminate\Support\Facades\Crypt::encryptString(json_encode(['secret_key' => '0x4AAA-secreta'])), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->reclamo = [
            'name' => 'Rosa Quispe Huamán', 'document_type' => 'DNI', 'document_number' => '45678912', 'address' => 'Av. Arequipa 1234, Lince', 'phone' => '987654321',
            'email' => 'rosa.quispe@correo.test', 'is_minor' => false, 'good_type' => 'servicio', 'good_description' => 'Corte de cabello', 'amount' => 35,
            'kind' => 'reclamo', 'detail' => 'No me atendieron a la hora.', 'request' => 'Que me devuelvan el adelanto.', 'accepted' => true,
        ];
    });

    it('con Cloudflare caído, el Libro de Reclamaciones recibe el reclamo y lo anota en errores', function () {
        Http::fake([TURNSTILE_PRUEBA => Http::failedConnection()]);

        $this->postJson('/api/complaints-book', $this->reclamo + ['turnstile_token' => 'humano'])->assertCreated();

        expect(DB::table('error_logs')->where('message', 'like', '%Cloudflare Turnstile no respondió%')->where('message', 'like', '%Libro de Reclamaciones%')->exists())->toBeTrue()
            ->and(DB::table('platform_integration_logs')->where('kind', 'captcha')->where('ok', false)->exists())->toBeTrue();
    });

    it('con Cloudflare caído también pasa si el navegador no pudo cargar la casilla (sin ficha)', function () {
        Http::fake([TURNSTILE_PRUEBA => Http::response('caído', 503)]);

        $this->postJson('/api/complaints-book', $this->reclamo)->assertCreated();
    });

    it('si Cloudflare responde que no pasó, se sigue bloqueando', function () {
        Http::fake([TURNSTILE_PRUEBA => Http::response(['success' => false, 'error-codes' => ['invalid-input-response']])]);

        $this->postJson('/api/complaints-book', $this->reclamo + ['turnstile_token' => 'robot'])->assertStatus(422)->assertJsonValidationErrors('turnstile_token');
        $this->postJson('/api/complaints-book', $this->reclamo)->assertStatus(422)->assertJsonValidationErrors('turnstile_token');

        expect(DB::table('error_logs')->where('message', 'like', '%Cloudflare Turnstile%')->exists())->toBeFalse();
    });

    it('con Cloudflare caído, la reserva pública no se bloquea por la verificación', function () {
        Http::fake([TURNSTILE_PRUEBA => Http::failedConnection()]);
        $co = $this->company();
        $request = \Illuminate\Http\Request::create('/api/public/booking/x/appointments', 'POST', ['turnstile_token' => 'humano']);

        \App\Support\Captcha::assert($request, 'booking');

        expect(DB::table('error_logs')->where('message', 'like', '%Página pública de reservas%')->exists())->toBeTrue();
    });
});

describe('El profesional solo toca a sus clientes', function () {
    beforeEach(function () {
        $this->co = $this->company(['business' => 'vet']);
        $this->servicio = $this->servicePriced($this->co->tenant, 80, 'Consulta');
        DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 30]);
        $this->ana = $this->professional($this->co, 'Ana Torres');
        $this->luis = $this->professional($this->co, 'Luis Rojas');
        $this->suya = $this->customer($this->co->tenant, 'Suya', 'De Ana');
        $this->ajena = $this->customer($this->co->tenant, 'Ajena', 'De Luis');
        $mascota = fn ($cliente, $nombre) => DB::table('customer_dependents')->insertGetId([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'customer_id' => $cliente->id, 'kind' => 'pet', 'name' => $nombre, 'created_at' => now(), 'updated_at' => now(),
        ], 'id');
        $this->mascotaAjena = $mascota($this->ajena, 'Firulais');
        $this->book($this->co, $this->suya, $this->servicio, $this->ana, $this->at($this->co->tenant, '10:00'), ['customer_dependent_id' => $mascota($this->suya, 'Michi')])->assertCreated();
        $this->book($this->co, $this->ajena, $this->servicio, $this->luis, $this->at($this->co->tenant, '11:00'), ['customer_dependent_id' => $this->mascotaAjena])->assertCreated();
    });

    it('no ve ni cambia las mascotas de un cliente que no atiende', function () {
        $mascota = $this->mascotaAjena;

        $this->as($this->ana->user)->getJson("/api/customers/{$this->ajena->id}/dependents")->assertNotFound();
        $this->as($this->ana->user)->postJson("/api/customers/{$this->ajena->id}/dependents", ['name' => 'Otra'])->assertNotFound();
        $this->as($this->ana->user)->putJson("/api/customers/{$this->ajena->id}/dependents/{$mascota}", ['name' => 'Cambiado'])->assertNotFound();
        $this->as($this->ana->user)->deleteJson("/api/customers/{$this->ajena->id}/dependents/{$mascota}")->assertNotFound();
        $this->as($this->ana->user)->getJson("/api/customers/{$this->suya->id}/dependents")->assertOk();
        $this->as($this->co->owner)->getJson("/api/customers/{$this->ajena->id}/dependents")->assertOk()->assertJsonPath('0.name', 'Firulais');

        expect(DB::table('customer_dependents')->where('id', $mascota)->value('name'))->toBe('Firulais');
    });

    it('no cambia los permisos de mensajes de un cliente que no atiende', function () {
        $this->as($this->ana->user)->putJson("/api/customers/{$this->ajena->id}/consents", ['email' => true])->assertNotFound();
        $this->as($this->ana->user)->putJson("/api/customers/{$this->suya->id}/consents", ['email' => true])->assertOk()->assertJsonPath('consents.email.granted', true);

        expect(DB::table('customer_consents')->where('customer_id', $this->ajena->id)->exists())->toBeFalse();
    });

    it('el tablero de clientes cuenta solo a sus clientes; la dueña ve a todos', function () {
        $total = fn ($response) => collect($response->json('stages'))->sum('customers');

        $suyo = $this->as($this->ana->user)->getJson('/api/customer-board')->assertOk();
        $todos = $this->as($this->co->owner)->getJson('/api/customer-board')->assertOk();

        expect($total($suyo))->toBe(1)
            ->and($total($todos))->toBe(DB::table('customers')->where('tenant_id', $this->co->tenant->id)->whereNull('deleted_at')->count());
    });
});

describe('Encuesta respondida dos veces a la vez', function () {
    it('se queda la primera respuesta y la segunda recibe «ya respondiste»', function () {
        $co = $this->company();
        $servicio = $this->servicePriced($co->tenant, 80, 'Limpieza facial');
        $ana = $this->professional($co, 'Ana Torres');
        $clienta = $this->customer($co->tenant, 'Lucía', 'Paredes');
        $cita = $this->book($co, $clienta, $servicio, $ana, $this->at($co->tenant, '10:00'))->assertCreated()->json('id');
        $id = (string) Str::uuid();
        $token = Str::random(32);
        DB::table('surveys')->insert([
            'id' => $id, 'tenant_id' => $co->tenant->id, 'appointment_id' => $cita, 'customer_id' => $clienta->id, 'professional_id' => $ana->id, 'service_id' => $servicio->id,
            'branch_id' => $co->branch->id, 'token' => $token, 'status' => 'sent', 'send_at' => now()->subHour(), 'sent_at' => now()->subHour(), 'expires_at' => now()->addDays(7), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $leida = DB::table('surveys')->where('id', $id)->first();
        $tenant = Tenant::find($co->tenant->id);

        $primera = Surveys::answer($tenant, $leida, 10, 'Excelente', false);
        $segunda = Surveys::answer($tenant, $leida, 2, 'Pésimo', false);

        expect($primera)->not->toBeNull()
            ->and($segunda)->toBeNull()
            ->and((int) DB::table('surveys')->where('id', $id)->value('score'))->toBe(10)
            ->and(DB::table('surveys')->where('id', $id)->value('comment'))->toBe('Excelente');

        $this->postJson("/api/public/surveys/{$token}", ['score' => 1])->assertStatus(409)->assertJsonPath('code', 'ALREADY_ANSWERED');
    });
});

describe('Niubiz: si se corta, una página clara en vez de una técnica', function () {
    beforeEach(function () {
        $this->co = $this->company();
        DB::table('companies')->where('tenant_id', $this->co->tenant->id)->update(['tax_id' => '20123456789']);
        $servicio = $this->servicePriced($this->co->tenant, 80, 'Limpieza facial');
        $clienta = $this->customer($this->co->tenant, 'Rosa', 'Quispe');
        Http::fake([NIUBIZ_PRUEBA . '/api.security/v1/security' => Http::response('eyJ.token.niubiz', 201)]);
        $this->as($this->co->owner)->putJson('/api/integrations/payments', [
            'provider' => 'niubiz', 'mode' => 'sandbox', 'public_key' => '456879852', 'username' => 'integraciones@niubiz.com.pe', 'secret_key' => '_7z3@8fF',
        ])->assertOk();
        $venta = $this->sell($this->co, [['type' => 'service', 'id' => $servicio->id]], ['customer_id' => $clienta->id]);
        $link = $this->as($this->co->owner)->postJson("/api/sales/{$venta['id']}/links")->assertCreated()->json();
        $this->app['auth']->forgetGuards();
        $this->link = DB::table('payment_links')->where('id', $link['id'])->first();
        $this->postJson("/api/pay/{$this->link->token}/start")->assertOk();
    });

    it('si Niubiz no abre el formulario, muestra una página propia con «Volver a intentar»', function () {
        Http::fake([NIUBIZ_PRUEBA . '/api.ecommerce/*' => Http::failedConnection()]);

        $page = $this->get("/api/pay/{$this->link->token}/niubiz")->assertOk();

        expect($page->headers->get('Content-Type'))->toContain('text/html')
            ->and($page->getContent())->toContain('data-niubiz-trouble')->toContain('Volver a intentar')->toContain("/api/pay/{$this->link->token}/niubiz")
            ->not->toContain('Exception');
    });

    it('si se corta al confirmar el pago, no lo marca rechazado y explica que revise el estado', function () {
        Http::fake([NIUBIZ_PRUEBA . '/api.authorization/*' => Http::failedConnection()]);

        $page = $this->post("/api/pay/{$this->link->token}/niubiz", ['transactionToken' => 'ABCDEF1234567890ABCDEF1234567890'])->assertOk();

        expect($page->getContent())->toContain('No pudimos confirmar tu pago')->toContain('Ver el estado de mi pago')
            ->and(DB::table('payment_links')->where('id', $this->link->id)->value('status'))->toBe('pending')
            ->and(DB::table('payment_events')->where('payment_link_id', $this->link->id)->where('event', 'payment.rejected')->exists())->toBeFalse();
    });

    it('un link que no existe también muestra la página propia', function () {
        $page = $this->get('/api/pay/' . Str::random(40) . '/niubiz')->assertNotFound();

        expect($page->getContent())->toContain('data-niubiz-trouble')->toContain('Este link de pago no existe.');
    });
});

describe('Plantillas de rubro: lo que dicen se aplica al crear la empresa', function () {
    it('en podología, terapia infantil y nutrición los puntos nacen apagados', function (string $slug) {
        $co = $this->company(['business' => $slug]);
        $tenant = Tenant::with('settings')->find($co->tenant->id);

        expect(AppointmentFlow::rule($tenant, 'loyalty.points_enabled'))->toBeFalse()
            ->and(Loyalty::rules($tenant)['points_enabled'])->toBeFalse();
    })->with(['podiatry', 'kids_therapy', 'nutrition']);

    it('en un salón los puntos siguen encendidos', function () {
        $co = $this->company(['business' => 'salon']);

        expect(AppointmentFlow::rule(Tenant::with('settings')->find($co->tenant->id), 'loyalty.points_enabled'))->toBeTrue();
    });

    it('en la clínica dental el recordatorio nace con 48 horas de anticipación', function () {
        $dental = $this->company(['business' => 'dental']);
        $salon = $this->company(['business' => 'salon']);

        expect((int) Messaging::settings($dental->tenant->id)['reminder']['options']['hours'])->toBe(48)
            ->and((int) Messaging::settings($salon->tenant->id)['reminder']['options']['hours'])->toBe(24)
            ->and(Messaging::settings($dental->tenant->id)['reminder']['enabled'])->toBeTrue()
            ->and(Messaging::settings($dental->tenant->id)['reminder']['custom'])->toBeFalse();
    });

    it('una empresa que ya existía no cambia: la traducción solo corre al crearla', function () {
        $co = $this->company(['business' => 'nutrition']);
        DB::table('tenant_settings')->where('tenant_id', $co->tenant->id)->update(['rules' => json_encode([])]);
        $tenant = Tenant::with('settings')->find($co->tenant->id);

        expect(AppointmentFlow::rule($tenant, 'loyalty.points_enabled'))->toBeTrue();
    });

    it('todas las reglas de las plantillas existen o están en la lista de traducciones', function () {
        $known = array_merge(array_keys(AppointmentFlow::RULE_DEFAULTS), array_keys(Tenant::TEMPLATE_RULE_ALIASES), [
            'booking.deposit_percent', 'customers.clinical_fields', 'professionals.license', 'reminders.hours_before',
            'dependents.enabled', 'dependents.required', 'noshow.keep_deposit',
        ]);
        $unknown = DB::table('business_types')->get(['slug', 'default_rules'])
            ->flatMap(fn ($type) => collect(array_keys(json_decode((string) $type->default_rules, true) ?: []))->reject(fn ($key) => in_array($key, $known, true))->map(fn ($key) => "{$type->slug}: {$key}"))
            ->values()->all();

        expect($unknown)->toBe([]);
    });
});
