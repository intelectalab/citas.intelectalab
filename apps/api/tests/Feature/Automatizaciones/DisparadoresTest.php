<?php

use App\Support\Automations;
use App\Support\CustomerStages;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->co = $this->company(['modules_off' => []]);
    $this->travelTo(Carbon::parse('2026-11-20 10:30', 'America/Lima'));

    DB::table('tenant_integrations')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'kind' => 'email', 'provider' => 'simulated', 'mode' => 'simulated',
        'settings' => json_encode(['from_address' => 'hola@empresa.test', 'from_name' => 'Empresa']), 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $this->servicio = $this->servicePriced($this->co->tenant, 60, 'Servicio automático');
    $this->pro = $this->professional($this->co);

    $this->encender = function (string $key, array $datos = []) {
        $id = collect($this->as($this->co->owner)->getJson('/api/automations')->assertOk()->json('automations'))->firstWhere('key', $key)['id'];
        $this->as($this->co->owner)->putJson("/api/automations/{$id}", ['active' => true] + $datos)->assertOk();
    };
    $this->clienta = function (string $nombre) {
        $c = $this->customer($this->co->tenant, $nombre, 'Automática');
        DB::table('customer_consents')->insert(['tenant_id' => $c->tenant_id, 'customer_id' => $c->id, 'channel' => 'email', 'granted' => true, 'granted_at' => now()]);

        return $c;
    };
    $this->visita = function (object $customer, Carbon $cuando, string $estado = 'completed', int $diasAdelante = 5) {
        $id = $this->book($this->co, $customer, $this->servicio, $this->pro, $this->at($this->co->tenant, '12:00', $diasAdelante))->assertCreated()->json('id');
        DB::table('appointments')->where('id', $id)->update([
            'status' => $estado, 'starts_at' => $cuando, 'ends_at' => $cuando->copy()->addHour(),
            'completed_at' => $estado === 'completed' ? $cuando->copy()->addHour() : null,
        ]);

        return $id;
    };
    $this->correosDe = fn (object $customer) => DB::table('messages')->where('tenant_id', $this->co->tenant->id)->where('kind', 'automation')->where('customer_id', $customer->id)->count();
});

it('primera atención: escribe al día siguiente de su primera cita atendida y no a quien ya venía', function () {
    ($this->encender)('first_visit');
    $nueva = ($this->clienta)('Nueva');
    $antigua = ($this->clienta)('Antigua');

    ($this->visita)($antigua, now()->subMonths(2), 'completed', 6);
    $this->travelTo(now()->addMinutes(5));
    ($this->visita)($nueva, now()->subHour(), 'completed', 5);
    ($this->visita)($antigua, now()->subHours(3), 'completed', 7);

    Automations::run($this->co->tenant, true);
    expect(($this->correosDe)($nueva))->toBe(0);

    $this->travelTo(now()->addDay()->addMinutes(5));
    Automations::run($this->co->tenant, true);
    Automations::run($this->co->tenant, true);

    expect(($this->correosDe)($nueva))->toBe(1)
        ->and(($this->correosDe)($antigua))->toBe(0);
});

it('cliente que dejó de venir: escribe al cumplir los días sin venir, salvo que tenga una cita próxima', function () {
    $this->setRules($this->co->tenant, ['crm.inactive_days' => 30]);
    ($this->encender)('inactive');
    $ausente = ($this->clienta)('Ausente');
    $conCita = ($this->clienta)('Reservada');
    $reciente = ($this->clienta)('Reciente');

    ($this->visita)($ausente, now()->subDays(29), 'completed', 5);
    ($this->visita)($conCita, now()->subDays(29)->subHours(2), 'completed', 6);
    $this->book($this->co, $conCita, $this->servicio, $this->pro, $this->at($this->co->tenant, '15:00', 10))->assertCreated();
    ($this->visita)($reciente, now()->subDays(5), 'completed', 7);

    $this->travelTo(now()->addDays(2));
    Automations::run($this->co->tenant, true);

    expect(($this->correosDe)($ausente))->toBe(1)
        ->and(($this->correosDe)($conCita))->toBe(0)
        ->and(($this->correosDe)($reciente))->toBe(0);
});

it('llegó a fidelizado: escribe cuando pasa a esa columna después de encenderla', function () {
    $this->as($this->co->owner)->getJson('/api/customer-board')->assertOk();
    $leal = ($this->clienta)('Leal');
    $antes = ($this->clienta)('Antes');
    $fidelizado = collect($this->as($this->co->owner)->getJson('/api/customer-board')->json('stages'))->firstWhere('system_key', 'loyal')['id'];

    $cambio = fn (object $c) => DB::table('customer_stage_changes')->where('customer_id', $c->id)->where('to_stage_id', $fidelizado);

    $this->as($this->co->owner)->putJson("/api/customers/{$antes->id}/stage", ['stage_id' => $fidelizado])->assertSuccessful();
    $cambio($antes)->update(['created_at' => now()]);
    $this->travelTo(now()->addMinutes(5));
    ($this->encender)('loyal');
    $this->travelTo(now()->addMinutes(5));
    $this->as($this->co->owner)->putJson("/api/customers/{$leal->id}/stage", ['stage_id' => $fidelizado])->assertSuccessful();
    $cambio($leal)->update(['created_at' => now()]);

    Automations::run($this->co->tenant, true);

    expect(CustomerStages::automatic())->toBeTrue()
        ->and(($this->correosDe)($leal))->toBe(1)
        ->and(($this->correosDe)($antes))->toBe(0);
});

it('a una misma persona le llega como mucho un mensaje automático al día', function () {
    ($this->encender)('birthday');
    ($this->encender)('no_show', ['delay_minutes' => 30]);
    $rosa = ($this->clienta)('Rosa');
    DB::table('customers')->where('id', $rosa->id)->update(['birth_date' => '1990-11-20']);

    $cita = ($this->visita)($rosa, now()->subHours(2), 'no_show', 5);
    DB::table('appointment_status_history')->insert(['tenant_id' => $this->co->tenant->id, 'appointment_id' => $cita, 'from_status' => 'confirmed', 'to_status' => 'no_show', 'created_at' => now()]);

    $this->travelTo(now()->addHour());
    Automations::run($this->co->tenant, true);

    expect(($this->correosDe)($rosa))->toBe(1);

    $this->travelTo(now()->addDay());
    Automations::run($this->co->tenant, true);

    expect(($this->correosDe)($rosa))->toBe(2);
});

it('sin permiso para recibir correos no se le escribe aunque cumpla la condición', function () {
    ($this->encender)('first_visit');
    $sinPermiso = $this->customer($this->co->tenant, 'Sin', 'Permiso');
    $this->travelTo(now()->addMinutes(5));
    ($this->visita)($sinPermiso, now()->subHour(), 'completed', 5);

    $this->travelTo(now()->addDays(2));
    Automations::run($this->co->tenant, true);

    expect(($this->correosDe)($sinPermiso))->toBe(0);
});
