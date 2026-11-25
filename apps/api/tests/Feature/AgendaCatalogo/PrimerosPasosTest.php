<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company();
});

function paso(array $pasos, string $clave): ?array
{
    return collect($pasos)->firstWhere('key', $clave);
}

describe('primeros pasos', function () {
    it('el dueño ve los siete pasos y se marcan solos con los datos reales', function () {
        $respuesta = $this->as($this->co->owner)->getJson('/api/tenant/onboarding')->assertOk();

        expect($respuesta->json('total'))->toBe(7)
            ->and($respuesta->json('visible'))->toBeTrue()
            ->and(paso($respuesta->json('steps'), 'services')['done'])->toBeTrue()
            ->and(paso($respuesta->json('steps'), 'team')['done'])->toBeFalse()
            ->and(paso($respuesta->json('steps'), 'appointment')['done'])->toBeFalse();

        $ana = $this->professional($this->co, 'Ana Torres');
        $servicio = $this->servicePriced($this->co->tenant, 80, 'Corte');
        $this->book($this->co, $this->customer($this->co->tenant), $servicio, $ana, $this->at($this->co->tenant, '10:00'))->assertCreated();

        $despues = $this->as($this->co->owner)->getJson('/api/tenant/onboarding')->assertOk()->json('steps');
        expect(paso($despues, 'team')['done'])->toBeTrue()
            ->and(paso($despues, 'users')['done'])->toBeTrue()
            ->and(paso($despues, 'appointment')['done'])->toBeTrue();
    });

    it('marca la empresa cuando tiene RUC', function () {
        DB::table('companies')->where('tenant_id', $this->co->tenant->id)->update(['tax_id' => '20123456789']);

        expect(paso($this->as($this->co->owner)->getJson('/api/tenant/onboarding')->json('steps'), 'company')['done'])->toBeTrue();
    });

    it('se puede ocultar y queda oculta', function () {
        $this->as($this->co->owner)->postJson('/api/tenant/onboarding/hide')->assertOk();

        $this->as($this->co->owner)->getJson('/api/tenant/onboarding')->assertOk()->assertJsonPath('visible', false);
    });

    it('solo la ven el dueño y el administrador', function () {
        $recepcion = $this->staff($this->co, 'reception');

        $this->as($recepcion)->getJson('/api/tenant/onboarding')->assertOk()->assertJsonPath('visible', false)->assertJsonPath('steps', []);
        $this->as($recepcion)->postJson('/api/tenant/onboarding/hide')->assertForbidden();
    });
});

describe('datos de ejemplo', function () {
    it('carga clientes, equipo y citas de ejemplo y luego los borra todos', function () {
        $antes = [
            'customers' => DB::table('customers')->where('tenant_id', $this->co->tenant->id)->count(),
            'professionals' => DB::table('professionals')->where('tenant_id', $this->co->tenant->id)->count(),
        ];

        $creado = $this->as($this->co->owner)->postJson('/api/tenant/sample-data')->assertCreated();
        expect($creado->json('customers'))->toBe(6)
            ->and($creado->json('professionals'))->toBe(2)
            ->and($creado->json('appointments'))->toBeGreaterThan(0)
            ->and($creado->json('status.loaded'))->toBeTrue();

        $this->as($this->co->owner)->postJson('/api/tenant/sample-data')->assertStatus(409)->assertJsonPath('code', 'ALREADY_LOADED');

        $borrado = $this->as($this->co->owner)->deleteJson('/api/tenant/sample-data')->assertOk();
        expect($borrado->json('kept'))->toBe(0)
            ->and($borrado->json('status.loaded'))->toBeFalse()
            ->and(DB::table('customers')->where('tenant_id', $this->co->tenant->id)->count())->toBe($antes['customers'])
            ->and(DB::table('professionals')->where('tenant_id', $this->co->tenant->id)->count())->toBe($antes['professionals'])
            ->and(DB::table('appointments')->where('tenant_id', $this->co->tenant->id)->count())->toBe(0);
    });

    it('lo que ya tiene ventas no se borra y se avisa', function () {
        $this->as($this->co->owner)->postJson('/api/tenant/sample-data')->assertCreated();
        $cita = DB::table('appointments')->where('tenant_id', $this->co->tenant->id)->first();
        DB::table('sales')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'branch_id' => $this->co->branch->id,
            'customer_id' => $cita->customer_id, 'appointment_id' => $cita->id, 'code' => 'V-EJ-1', 'status' => 'paid', 'total' => 10, 'paid' => 10, 'balance' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $borrado = $this->as($this->co->owner)->deleteJson('/api/tenant/sample-data')->assertOk();

        expect($borrado->json('kept'))->toBeGreaterThan(0)
            ->and($borrado->json('message'))->toContain('no se pudieron borrar')
            ->and(DB::table('appointments')->where('id', $cita->id)->exists())->toBeTrue();
    });

    it('no toca los datos reales de la empresa', function () {
        $real = $this->customer($this->co->tenant, 'Clienta', 'Real');
        $this->as($this->co->owner)->postJson('/api/tenant/sample-data')->assertCreated();
        $this->as($this->co->owner)->deleteJson('/api/tenant/sample-data')->assertOk();

        expect(DB::table('customers')->where('id', $real->id)->exists())->toBeTrue();
    });

    it('las citas de ejemplo van solo al equipo de ejemplo, no al real', function () {
        $real = $this->professional($this->co, 'Ana Real');
        $this->as($this->co->owner)->postJson('/api/tenant/sample-data')->assertCreated();

        $citas = DB::table('appointments')->where('tenant_id', $this->co->tenant->id)->get();
        expect($citas->count())->toBeGreaterThan(0)
            ->and($citas->pluck('professional_id')->contains($real->id))->toBeFalse();
    });

    it('recepción no puede cargar datos de ejemplo', function () {
        $this->as($this->staff($this->co, 'reception'))->postJson('/api/tenant/sample-data')->assertForbidden();
    });
});
