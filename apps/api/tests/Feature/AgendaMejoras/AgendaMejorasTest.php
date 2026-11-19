<?php

use App\Support\Access;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->co = $this->company();
    $this->servicio = $this->servicePriced($this->co->tenant, 80, 'Corte clásico');
    DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 60]);
    $this->ana = $this->professional($this->co, 'Ana Torres');
    $this->clienta = $this->customer($this->co->tenant, 'Lucía', 'Paredes');
});

function correoSimulado(string $tenantId): void
{
    DB::table('tenant_integrations')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'kind' => 'email', 'provider' => 'simulated', 'mode' => 'simulated',
        'settings' => json_encode(['from_address' => 'citas@empresa.test', 'from_name' => 'Empresa']), 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);
}


describe('avisos al elegir el cliente', function () {
    it('muestra saldo pendiente y faltas recientes', function () {
        DB::table('sales')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'branch_id' => $this->co->branch->id,
            'customer_id' => $this->clienta->id, 'code' => 'V-PRUEBA-1', 'status' => 'partial', 'total' => 100, 'paid' => 40, 'balance' => 60,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $cita = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant, '10:00'))->assertCreated()->json('id');
        DB::table('appointments')->where('id', $cita)->update(['status' => 'no_show', 'starts_at' => now()->subDays(5), 'ends_at' => now()->subDays(5)->addHour()]);

        $notas = collect($this->as($this->co->owner)->getJson("/api/appointments/customer-notes?customer_id={$this->clienta->id}&service_id={$this->servicio->id}")
            ->assertOk()->json('notes'))->keyBy('kind');

        expect($notas['debt']['text'])->toContain('60')
            ->and($notas['debt']['text'])->toContain('1 venta')
            ->and($notas['no_show']['text'])->toContain('1 cita');
    });

    it('no muestra nada si el cliente está al día', function () {
        $this->as($this->co->owner)->getJson("/api/appointments/customer-notes?customer_id={$this->clienta->id}")
            ->assertOk()->assertJsonPath('notes', []);
    });

    it('no deja ver los avisos de clientes de otra empresa', function () {
        $otra = $this->company();
        $ajena = $this->customer($otra->tenant);

        $this->as($this->co->owner)->getJson("/api/appointments/customer-notes?customer_id={$ajena->id}")->assertNotFound();
    });
});

describe('doble envío al crear', function () {
    it('dos envíos con la misma llave crean una sola cita y devuelven la misma', function () {
        $llave = (string) Str::uuid();
        $hora = $this->at($this->co->tenant, '11:00');

        $primera = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $hora, ['request_key' => $llave])->assertCreated()->json();
        $segunda = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $hora, ['request_key' => $llave])->assertCreated()->json();

        expect($segunda['id'])->toBe($primera['id'])
            ->and($segunda['repeated'])->toBeTrue()
            ->and(DB::table('appointments')->where('customer_id', $this->clienta->id)->count())->toBe(1);
    });

    it('con otra llave el horario ya ocupado se rechaza', function () {
        $hora = $this->at($this->co->tenant, '11:00');

        $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $hora, ['request_key' => (string) Str::uuid()])->assertCreated();
        $otro = $this->customer($this->co->tenant, 'Mario', 'Ríos');
        $this->book($this->co, $otro, $this->servicio, $this->ana, $hora, ['request_key' => (string) Str::uuid()])->assertStatus(409);
    });
});

describe('permiso por acción en la cita', function () {
    beforeEach(function () {
        $this->recepcion = $this->staff($this->co, 'reception');
        $this->rol = DB::table('roles')->where('tenant_id', $this->co->tenant->id)->where('slug', 'reception')->value('id');
        $this->cita = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant, '12:00'))->assertCreated()->json('id');
    });

    it('recepción de fábrica tiene las seis acciones', function () {
        foreach (Access::APPOINTMENT_STEPS as $paso) {
            expect($this->recepcion->allows("agenda.{$paso}"))->toBeTrue();
        }
    });

    it('sin el permiso de cancelar, no cancela pero sí confirma', function () {
        Access::setRolePermissions($this->rol, array_diff(Access::rolePermissions($this->rol), ['agenda.cancel']));

        $vista = $this->as($this->recepcion)->getJson("/api/appointments/{$this->cita}")->assertOk();
        expect($vista->json('next_statuses'))->not->toContain('cancelled')->toContain('confirmed');

        $this->as($this->recepcion)->postJson("/api/appointments/{$this->cita}/status", ['status' => 'cancelled', 'reason' => 'x'])
            ->assertForbidden()->assertJsonPath('permission', 'agenda.cancel');
        $this->as($this->recepcion)->postJson("/api/appointments/{$this->cita}/status", ['status' => 'confirmed'])->assertOk();
    });

    it('la migración da las acciones nuevas a quien tenía editar agenda', function () {
        $propio = (string) Str::uuid();
        DB::table('roles')->insert(['id' => $propio, 'tenant_id' => $this->co->tenant->id, 'slug' => 'propio', 'name' => 'Rol propio', 'permissions_seeded_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        Access::setRolePermissions($propio, ['agenda.view', 'agenda.edit']);
        $ids = Access::permissionIds();
        DB::table('user_permissions')->insert(['tenant_id' => $this->co->tenant->id, 'user_id' => $this->recepcion->id, 'permission_id' => $ids['agenda.edit'], 'granted' => false, 'created_at' => now()]);

        (require database_path('migrations/2026_11_18_000020_permisos_por_accion_de_la_cita.php'))->up();

        expect(Access::rolePermissions($propio))->toContain('agenda.cancel', 'agenda.confirm', 'agenda.noshow')
            ->and(DB::table('user_permissions')->where('user_id', $this->recepcion->id)->where('permission_id', $ids['agenda.cancel'])->value('granted'))->toBeFalse();
    });
});

describe('lista de espera', function () {
    beforeEach(function () {
        correoSimulado($this->co->tenant->id);
        $this->cita = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant, '15:00'))->assertCreated()->json('id');
        $this->primera = $this->customer($this->co->tenant, 'Rosa', 'Primera');
        $this->segunda = $this->customer($this->co->tenant, 'Eva', 'Segunda');
        $anotar = fn (object $customer) => $this->as($this->co->owner)->postJson('/api/waitlist', [
            'customer_id' => $customer->id, 'service_id' => $this->servicio->id, 'branch_id' => $this->co->branch->id,
            'date_from' => now('America/Lima')->format('Y-m-d'),
        ])->assertCreated()->json('id');
        $this->entradaPrimera = $anotar($this->primera);
        $this->travel(1)->minutes();
        $this->entradaSegunda = $anotar($this->segunda);
        $this->cancelar = fn () => $this->as($this->co->owner)->postJson("/api/appointments/{$this->cita}/status", ['status' => 'cancelled', 'reason' => 'No puede'])->assertOk();
        $this->avisos = fn () => DB::table('messages')->where('kind', 'waitlist_slot')->pluck('customer_id')->all();
    });

    it('por defecto avisa a todos los que encajan', function () {
        ($this->cancelar)();

        expect(($this->avisos)())->toHaveCount(2);
    });

    it('solo al primero, y pasa al siguiente cuando vence su plazo', function () {
        $this->setRules($this->co->tenant, ['waitlist.notify_mode' => 'first', 'waitlist.reply_hours' => 2]);

        ($this->cancelar)();

        expect(($this->avisos)())->toBe([$this->primera->id])
            ->and(DB::table('waitlist_entries')->where('id', $this->entradaPrimera)->value('status'))->toBe('notified');

        $this->travel(3)->hours();
        $this->as($this->co->owner)->getJson('/api/waitlist')->assertOk();

        expect(($this->avisos)())->toContain($this->segunda->id)->toHaveCount(2)
            ->and(DB::table('waitlist_entries')->where('id', $this->entradaPrimera)->value('status'))->toBe('waiting')
            ->and(DB::table('waitlist_offers')->where('waitlist_entry_id', $this->entradaPrimera)->value('status'))->toBe('expired');
    });

    it('si el horario se ocupó, no pasa al siguiente', function () {
        $this->setRules($this->co->tenant, ['waitlist.notify_mode' => 'first', 'waitlist.reply_hours' => 1]);
        ($this->cancelar)();
        $otro = $this->customer($this->co->tenant, 'Toma', 'Horario');
        $this->book($this->co, $otro, $this->servicio, $this->ana, $this->at($this->co->tenant, '15:00'))->assertCreated();

        $this->travel(2)->hours();
        $this->as($this->co->owner)->getJson('/api/waitlist')->assertOk();

        expect(($this->avisos)())->toBe([$this->primera->id]);
    });

    it('apagada, no avisa a nadie ni aparece en el portal', function () {
        $this->setRules($this->co->tenant, ['waitlist.enabled' => false]);

        ($this->cancelar)()->assertJsonPath('waitlist_matches', 0);

        expect(($this->avisos)())->toBe([]);
        $this->as($this->primera)->getJson('/api/portal/waitlist')->assertOk()->assertExactJson([]);
        $this->as($this->primera)->getJson('/api/portal/overview')->assertOk()->assertJsonPath('policies.waitlist_enabled', false);
        $this->as($this->co->owner)->postJson('/api/waitlist', [
            'customer_id' => $this->clienta->id, 'service_id' => $this->servicio->id, 'branch_id' => $this->co->branch->id,
            'date_from' => now('America/Lima')->format('Y-m-d'),
        ])->assertStatus(422);
    });

    it('la empresa guarda la regla desde Configuración', function () {
        $this->as($this->co->owner)->putJson('/api/tenant-settings', ['waitlist_rules' => ['enabled' => true, 'notify_mode' => 'first', 'reply_hours' => 4]])
            ->assertOk()->assertJsonPath('waitlist_rules.notify_mode', 'first')->assertJsonPath('waitlist_rules.reply_hours', 4);
    });
});

describe('enviar recordatorio', function () {
    beforeEach(function () {
        $this->cita = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant, '16:00'))->assertCreated()->json('id');
    });

    it('envía el recordatorio por correo y lo deja en la auditoría', function () {
        correoSimulado($this->co->tenant->id);

        $this->as($this->co->owner)->postJson("/api/appointments/{$this->cita}/remind")->assertOk();

        expect(DB::table('messages')->where('kind', 'reminder')->where('entity_id', $this->cita)->count())->toBe(1)
            ->and(DB::table('audit_events')->where('action', 'appointment.reminded')->exists())->toBeTrue();
    });

    it('sin correo configurado lo dice con palabras', function () {
        $this->as($this->co->owner)->postJson("/api/appointments/{$this->cita}/remind")->assertStatus(422)->assertJsonPath('code', 'NOT_SENT');
    });

    it('una cita cancelada no recibe recordatorio', function () {
        $this->as($this->co->owner)->postJson("/api/appointments/{$this->cita}/status", ['status' => 'cancelled', 'reason' => 'x'])->assertOk();

        $this->as($this->co->owner)->postJson("/api/appointments/{$this->cita}/remind")->assertStatus(422);
    });
});
