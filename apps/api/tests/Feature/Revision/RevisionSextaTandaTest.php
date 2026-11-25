<?php

use App\Models\User;
use App\Support\PlatformMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    PlatformMail::$outbox = [];
    $this->co = $this->company(['modules_off' => []]);
    DB::table('tenant_integrations')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'kind' => 'email', 'provider' => 'simulated', 'mode' => 'simulated',
        'settings' => json_encode(['from_address' => 'citas@empresa.test', 'from_name' => 'Empresa']), 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $this->servicio = $this->servicePriced($this->co->tenant, 80, 'Terapia');
    DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 60]);
    $this->ana = $this->professional($this->co, 'Ana Torres');
    $this->clienta = $this->customer($this->co->tenant, 'Rebeca', 'Vargas');
    $this->dia = $this->workday($this->co->tenant, 2);
    DB::table('holidays')->where('tenant_id', $this->co->tenant->id)->delete();
});

it('el aviso de lista de espera al reprogramar no lleva el nombre, el código ni el enlace de la cita de otra persona', function () {
    DB::table('message_settings')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'kind' => 'waitlist_slot', 'enabled' => true,
        'subject' => 'Se liberó un horario', 'body' => 'Hola [Nombre]: se liberó [Fecha] [Hora]. Código [Código]. Enlace [Enlace].',
        'options' => json_encode([]), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $cita = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->dia->format('Y-m-d') . 'T10:00')->assertCreated()->json('id');
    $codigo = DB::table('appointments')->where('id', $cita)->value('code');
    $mateo = $this->customer($this->co->tenant, 'Mateo', 'Huamán');
    DB::table('waitlist_entries')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'branch_id' => $this->co->branch->id, 'customer_id' => $mateo->id,
        'service_id' => $this->servicio->id, 'date_from' => now('America/Lima')->format('Y-m-d'), 'status' => 'waiting',
    ]);

    $this->as($this->co->owner)->postJson("/api/appointments/{$cita}/reschedule", ['starts_at' => $this->dia->format('Y-m-d') . 'T15:00'])->assertOk();

    $aviso = DB::table('messages')->where('tenant_id', $this->co->tenant->id)->where('kind', 'waitlist_slot')->where('customer_id', $mateo->id)->first();

    expect($aviso)->not->toBeNull()
        ->and($aviso->body)->toContain('Mateo')
        ->and($aviso->body)->not->toContain('Rebeca')
        ->and($aviso->body)->not->toContain($codigo)
        ->and($aviso->body)->not->toContain('/cita/');
});

it('el aviso de lista de espera al reprogramar saluda a quien espera aunque la plantilla sea la de siempre', function () {
    $cita = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->dia->format('Y-m-d') . 'T10:00')->assertCreated()->json('id');
    $mateo = $this->customer($this->co->tenant, 'Mateo', 'Huamán');
    DB::table('waitlist_entries')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'branch_id' => $this->co->branch->id, 'customer_id' => $mateo->id,
        'service_id' => $this->servicio->id, 'date_from' => now('America/Lima')->format('Y-m-d'), 'status' => 'waiting',
    ]);

    $this->as($this->co->owner)->postJson("/api/appointments/{$cita}/reschedule", ['starts_at' => $this->dia->format('Y-m-d') . 'T15:00'])->assertOk();

    $aviso = DB::table('messages')->where('tenant_id', $this->co->tenant->id)->where('kind', 'waitlist_slot')->where('customer_id', $mateo->id)->first();

    expect($aviso->body)->toContain('Hola Mateo')
        ->and($aviso->body)->not->toContain('Rebeca');
});

it('al cruzarse con otra cita el detalle separa el código de la hora', function () {
    $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->dia->format('Y-m-d') . 'T10:00')->assertCreated();
    $codigo = DB::table('appointments')->where('tenant_id', $this->co->tenant->id)->value('code');
    $otra = $this->customer($this->co->tenant, 'Rosa', 'Vega');

    $res = $this->book($this->co, $otra, $this->servicio, $this->ana, $this->dia->format('Y-m-d') . 'T10:00');

    $detalle = collect($res->json('reasons') ?? [])->firstWhere('code', 'DOUBLE_BOOKING')['detail'] ?? '';

    expect($detalle)->toBe("{$codigo} 10:00–11:00");
});

it('para borrar una empresa acepta su nombre sin tildes ni mayúsculas, pero no otro nombre', function () {
    DB::table('tenants')->where('id', $this->co->tenant->id)->update(['name' => 'Clínica Ñandú Él']);
    $admin = User::forceCreate([
        'id' => (string) Str::uuid(),
        'first_name' => 'Admin', 'last_name' => 'Consola', 'email' => 'rev6.' . Str::lower(Str::random(6)) . '@plataforma.test',
        'password_hash' => bcrypt('clave-de-prueba-123'), 'status' => 'active', 'tenant_id' => null,
    ]);

    $this->as($admin)->deleteJson("/api/tenants/{$this->co->tenant->id}", ['reason' => 'Lo pidió la dueña por correo', 'confirm_name' => 'clinica nandu'])
        ->assertStatus(422)->assertJsonValidationErrors('confirm_name');
    $this->as($admin)->deleteJson("/api/tenants/{$this->co->tenant->id}", ['reason' => 'Lo pidió la dueña por correo', 'confirm_name' => '  CLINICA  nandu el '])
        ->assertOk()->assertJsonPath('status', 'inactive');
});

it('al programar el borrado de una empresa la fecha sale en palabras', function () {
    $admin = User::forceCreate([
        'id' => (string) Str::uuid(),
        'first_name' => 'Admin', 'last_name' => 'Consola', 'email' => 'rev6.' . Str::lower(Str::random(6)) . '@plataforma.test',
        'password_hash' => bcrypt('clave-de-prueba-123'), 'status' => 'active', 'tenant_id' => null,
    ]);
    $this->travelTo(now('America/Lima')->setDate(2026, 11, 25)->setTime(1, 30));

    $res = $this->as($admin)->deleteJson("/api/tenants/{$this->co->tenant->id}", ['reason' => 'Lo pidió la dueña por correo', 'confirm_name' => $this->co->tenant->name])->assertOk();

    expect($res->json('message'))->toContain('se borran el 23 de febrero de 2027')
        ->and($res->json('message'))->not->toMatch('/\d{4}-\d{2}-\d{2}/');
});
