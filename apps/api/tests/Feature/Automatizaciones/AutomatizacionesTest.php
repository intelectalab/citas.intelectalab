<?php

use App\Support\Automations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function correoDePrueba(string $tenantId): void
{
    DB::table('tenant_integrations')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'kind' => 'email', 'provider' => 'simulated', 'mode' => 'simulated',
        'settings' => json_encode(['from_address' => 'hola@empresa.test', 'from_name' => 'Empresa']), 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

function aceptaCorreos(object $customer): void
{
    DB::table('customer_consents')->insert([
        'tenant_id' => $customer->tenant_id, 'customer_id' => $customer->id, 'channel' => 'email', 'granted' => true, 'granted_at' => now(),
    ]);
}

function cuponDe(string $tenantId, string $code): string
{
    $id = (string) Str::uuid();
    DB::table('coupons')->insert(['id' => $id, 'tenant_id' => $tenantId, 'code' => $code, 'discount_type' => 'percent', 'discount_value' => 10]);

    return $id;
}

beforeEach(function () {
    $this->co = $this->company(['modules_off' => []]);
    $this->travelTo(Carbon::parse('2026-11-20 10:30', 'America/Lima'));

    $this->reglas = fn ($user = null) => collect($this->as($user ?? $this->co->owner)->getJson('/api/automations')->assertOk()->json('automations'))->keyBy('key');
    $this->guardar = function (string $key, array $datos, $user = null) {
        $id = ($this->reglas)()[$key]['id'];

        return $this->as($user ?? $this->co->owner)->putJson("/api/automations/{$id}", $datos);
    };
    $this->correos = fn () => DB::table('messages')->where('tenant_id', $this->co->tenant->id)->where('kind', 'automation');
});

it('cada empresa tiene sus cinco automatizaciones apagadas de fábrica', function () {
    $reglas = ($this->reglas)();

    expect($reglas->keys()->sort()->values()->all())->toBe(['birthday', 'first_visit', 'inactive', 'loyal', 'no_show'])
        ->and($reglas->every(fn ($r) => $r['active'] === false))->toBeTrue()
        ->and(DB::table('automation_rules')->where('tenant_id', $this->co->tenant->id)->count())->toBe(5);
});

it('no deja encender sin el correo de la empresa configurado', function () {
    ($this->guardar)('birthday', ['active' => true])->assertStatus(422)->assertJsonValidationErrors('active');

    correoDePrueba($this->co->tenant->id);
    ($this->guardar)('birthday', ['active' => true])->assertOk();

    $regla = DB::table('automation_rules')->where('tenant_id', $this->co->tenant->id)->where('trigger_event', 'birthday')->first();
    expect((bool) $regla->is_active)->toBeTrue()->and($regla->enabled_at)->not->toBeNull();

    ($this->guardar)('birthday', ['active' => false])->assertOk();
    expect(DB::table('automation_rules')->where('id', $regla->id)->value('enabled_at'))->toBeNull();
});

it('si el mensaje usa [Cupón] exige elegir un cupón, y solo de la misma empresa', function () {
    $otra = $this->company(['modules_off' => []]);
    $ajeno = cuponDe($otra->tenant->id, 'AJENO10');
    $mio = cuponDe($this->co->tenant->id, 'MIO10');

    ($this->guardar)('birthday', ['active' => false, 'body' => 'Tu regalo: [Cupón]'])->assertStatus(422)->assertJsonValidationErrors('coupon_id');
    ($this->guardar)('birthday', ['active' => false, 'body' => 'Tu regalo: [Cupón]', 'coupon_id' => $ajeno])->assertStatus(422)->assertJsonValidationErrors('coupon_id');
    ($this->guardar)('birthday', ['active' => false, 'body' => 'Tu regalo: [Cupón]', 'coupon_id' => $mio])->assertOk();

    expect(($this->reglas)()['birthday']['coupon_code'])->toBe('MIO10');
});

it('el día del cumpleaños envía un correo con el nombre y no lo repite', function () {
    correoDePrueba($this->co->tenant->id);
    $cumple = $this->customer($this->co->tenant, 'Rosa', 'Cumple');
    DB::table('customers')->where('id', $cumple->id)->update(['birth_date' => '1990-11-20']);
    aceptaCorreos($cumple);
    $sinPermiso = $this->customer($this->co->tenant, 'Sin', 'Permiso');
    DB::table('customers')->where('id', $sinPermiso->id)->update(['birth_date' => '1991-11-20']);
    $otroDia = $this->customer($this->co->tenant, 'Otro', 'Dia');
    DB::table('customers')->where('id', $otroDia->id)->update(['birth_date' => '1991-05-02']);
    aceptaCorreos($otroDia);

    ($this->guardar)('birthday', ['active' => true])->assertOk();
    Automations::run($this->co->tenant, true);
    Automations::run($this->co->tenant, true);

    $correos = ($this->correos)()->get();
    expect($correos)->toHaveCount(1)
        ->and($correos[0]->customer_id)->toBe($cumple->id)
        ->and($correos[0]->subject)->toContain('Rosa')
        ->and($correos[0]->body)->toContain('no quieres recibir')
        ->and(DB::table('automation_logs')->where('customer_id', $cumple->id)->value('result'))->toBe('sent')
        ->and(($this->reglas)()['birthday']['results']['sent_month'])->toBe(1);
});

it('antes de la hora de envío no sale nada', function () {
    correoDePrueba($this->co->tenant->id);
    $cumple = $this->customer($this->co->tenant, 'Rosa', 'Cumple');
    DB::table('customers')->where('id', $cumple->id)->update(['birth_date' => '1990-11-20']);
    aceptaCorreos($cumple);

    ($this->guardar)('birthday', ['active' => true, 'send_time' => '11:00'])->assertOk();
    Automations::run($this->co->tenant, true);
    expect(($this->correos)()->count())->toBe(0);

    $this->travelTo(Carbon::parse('2026-11-20 11:05', 'America/Lima'));
    Automations::run($this->co->tenant, true);
    expect(($this->correos)()->count())->toBe(1);
});

it('a quien no asistió le escribe pasado el tiempo de espera', function () {
    correoDePrueba($this->co->tenant->id);
    ($this->guardar)('no_show', ['active' => true, 'delay_minutes' => 60])->assertOk();

    $clienta = $this->customer($this->co->tenant, 'Faltó', 'Hoy');
    aceptaCorreos($clienta);
    $servicio = $this->servicePriced($this->co->tenant, 50);
    $pro = $this->professional($this->co);
    $cita = $this->book($this->co, $clienta, $servicio, $pro, $this->at($this->co->tenant, '10:00'))->assertCreated()->json('id');
    DB::table('appointments')->where('id', $cita)->update(['status' => 'no_show']);
    DB::table('appointment_status_history')->insert(['tenant_id' => $this->co->tenant->id, 'appointment_id' => $cita, 'from_status' => 'confirmed', 'to_status' => 'no_show', 'created_at' => now()]);

    Automations::run($this->co->tenant, true);
    expect(($this->correos)()->count())->toBe(0);

    $this->travelTo(now()->addMinutes(61));
    Automations::run($this->co->tenant, true);

    expect(($this->correos)()->count())->toBe(1)
        ->and(DB::table('automation_logs')->where('customer_id', $clienta->id)->value('appointment_id'))->toBe($cita);
});

it('con el módulo apagado no corre ni se puede abrir', function () {
    correoDePrueba($this->co->tenant->id);
    ($this->guardar)('birthday', ['active' => true])->assertOk();
    $cumple = $this->customer($this->co->tenant, 'Rosa', 'Cumple');
    DB::table('customers')->where('id', $cumple->id)->update(['birth_date' => '1990-11-20']);
    aceptaCorreos($cumple);

    DB::table('tenant_settings')->where('tenant_id', $this->co->tenant->id)->update(['modules_off' => json_encode(['automations'])]);
    self::forgetCaches();
    $tenant = \App\Models\Tenant::find($this->co->tenant->id);

    expect(Automations::run($tenant, true))->toBe(0);
    $this->as($this->co->owner)->getJson('/api/automations')->assertForbidden();
});

it('la gerencia solo mira, el profesional no entra y nadie toca las de otra empresa', function () {
    $gerente = $this->staff($this->co, 'manager');
    $pro = $this->professional($this->co);

    ($this->reglas)($gerente);
    ($this->guardar)('birthday', ['active' => false, 'subject' => 'Cambio'], $gerente)->assertForbidden();
    $this->as($pro->user)->getJson('/api/automations')->assertForbidden();

    $otra = $this->company(['modules_off' => []]);
    $ajena = collect($this->as($otra->owner)->getJson('/api/automations')->json('automations'))->firstWhere('key', 'birthday')['id'];
    $this->as($this->co->owner)->putJson("/api/automations/{$ajena}", ['active' => false, 'subject' => 'Hackeado'])->assertNotFound();

    expect(DB::table('automation_rules')->where('id', $ajena)->value('subject'))->not->toBe('Hackeado');
});
