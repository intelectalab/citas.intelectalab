<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->co = $this->company();
    $this->servicio = $this->servicePriced($this->co->tenant, 50, 'Corte');
    DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 60]);
    $this->ana = $this->professional($this->co, 'Ana Torres');
    $this->clienta = $this->customer($this->co->tenant, 'Lucía', 'Paredes');
    $this->inactivo = DB::table('statuses')->join('status_modules', 'status_modules.id', '=', 'statuses.module_id')
        ->where('status_modules.name', 'professionals')->where('statuses.slug', 'inactive')->value('statuses.id');

    $this->desactivar = fn (array $extra = []) => $this->as($this->co->owner)->putJson("/api/professionals/{$this->ana->id}", $extra + ['status_id' => $this->inactivo]);
});

it('alguien recién creado, con horario pero sin historia, se borra sin error', function () {
    DB::table('service_professionals')->insert(['tenant_id' => $this->co->tenant->id, 'service_id' => $this->servicio->id, 'professional_id' => $this->ana->id]);
    DB::table('availability_blocks')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'professional_id' => $this->ana->id, 'branch_id' => $this->co->branch->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'reason' => 'Trámite', 'created_at' => now()]);

    $this->as($this->co->owner)->deleteJson("/api/professionals/{$this->ana->id}")->assertOk();

    expect(DB::table('professionals')->where('id', $this->ana->id)->exists())->toBeFalse()
        ->and(DB::table('professional_schedules')->where('professional_id', $this->ana->id)->exists())->toBeFalse()
        ->and(DB::table('service_professionals')->where('professional_id', $this->ana->id)->exists())->toBeFalse();
});

it('con citas no se borra: avisa y ofrece desactivar', function () {
    $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant, '10:00'))->assertCreated();

    $this->as($this->co->owner)->deleteJson("/api/professionals/{$this->ana->id}")
        ->assertStatus(409)
        ->assertJsonPath('code', 'HAS_HISTORY')
        ->assertJsonPath('can_deactivate', true)
        ->assertJsonPath('future_count', 1);

    expect(DB::table('professionals')->where('id', $this->ana->id)->exists())->toBeTrue();
});

it('sus comisiones nunca se pierden, ni las pagadas', function () {
    DB::table('commission_entries')->insert([
        'tenant_id' => $this->co->tenant->id, 'professional_id' => $this->ana->id, 'source' => 'sale', 'description' => 'Corte',
        'amount' => 10, 'period' => now()->format('Y-m'), 'status' => 'paid', 'earned_at' => now(), 'paid_at' => now(),
    ]);

    $this->as($this->co->owner)->deleteJson("/api/professionals/{$this->ana->id}")->assertStatus(409)->assertJsonPath('code', 'HAS_HISTORY');

    expect(DB::table('commission_entries')->where('professional_id', $this->ana->id)->count())->toBe(1);

    expect(fn () => DB::table('professionals')->where('id', $this->ana->id)->delete())->toThrow(\Illuminate\Database\QueryException::class);
});

it('desactivar con citas por venir pide qué hacer y no cambia nada hasta confirmarlo', function () {
    $cita = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant, '10:00'))->assertCreated()->json('id');

    ($this->desactivar)()
        ->assertStatus(422)
        ->assertJsonPath('code', 'HAS_FUTURE')
        ->assertJsonPath('future_count', 1)
        ->assertJsonPath('appointments.0.id', $cita);

    expect(DB::table('professionals')->where('id', $this->ana->id)->value('status_id'))->not->toBe($this->inactivo);

    ($this->desactivar)(['future_action' => 'keep'])->assertOk()->assertJsonPath('future_appointments', 1);

    expect(DB::table('professionals')->where('id', $this->ana->id)->value('status_id'))->toBe($this->inactivo)
        ->and(DB::table('appointments')->where('id', $cita)->value('status'))->toBe('pending');
});

it('desactivar sin citas por venir no pregunta', function () {
    ($this->desactivar)()->assertOk()->assertJsonMissingPath('future_appointments');
});

it('una vez inactivo ya no se le puede agendar', function () {
    ($this->desactivar)()->assertOk();

    $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant, '10:00'), ['override_reason' => 'Igual'])
        ->assertStatus(409)
        ->assertJsonPath('reasons.0.code', 'PRO_INACTIVE');
});
