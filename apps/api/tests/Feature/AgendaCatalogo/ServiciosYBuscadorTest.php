<?php

use App\Models\BusinessType;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company();
    $this->servicio = $this->servicePriced($this->co->tenant, 80, 'Limpieza facial');
    DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 60]);
    $this->ana = $this->professional($this->co, 'Ana Torres');
    $this->clienta = $this->customer($this->co->tenant, 'Lucía', 'Paredes');
});

function datosServicio(object $test, array $extra = []): array
{
    return $extra + [
        'name' => 'Masaje', 'duration_minutes' => 30, 'price' => 100,
        'status_id' => DB::table('statuses')->where('slug', 'active')->whereIn('module_id', DB::table('status_modules')->where('name', 'services')->select('id'))->value('id'),
    ];
}

describe('servicios', function () {
    it('la duración mínima es de 5 minutos', function () {
        $this->as($this->co->owner)->postJson('/api/services', datosServicio($this, ['duration_minutes' => 4]))
            ->assertStatus(422)->assertJsonPath('errors.duration_minutes.0', 'La duración mínima de un servicio es de 5 minutos.');

        $this->as($this->co->owner)->postJson('/api/services', datosServicio($this, ['duration_minutes' => 5]))->assertCreated();
    });

    it('un servicio con citas por venir no se borra: queda inactivo y avisa', function () {
        $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant, '10:00'))->assertCreated();

        $impacto = $this->as($this->co->owner)->getJson("/api/services/{$this->servicio->id}/impact")->assertOk();
        expect($impacto->json('future_count'))->toBe(1)
            ->and($impacto->json('appointments.0.customer'))->toContain('Lucía');

        $this->as($this->co->owner)->deleteJson("/api/services/{$this->servicio->id}")
            ->assertOk()->assertJsonPath('deactivated', true)->assertJsonPath('future_appointments', 1);

        expect(DB::table('services')->where('id', $this->servicio->id)->exists())->toBeTrue()
            ->and(DB::table('statuses')->where('id', DB::table('services')->where('id', $this->servicio->id)->value('status_id'))->value('slug'))->toBe('inactive');
    });

    it('un servicio sin citas sí se borra', function () {
        $nuevo = $this->as($this->co->owner)->postJson('/api/services', datosServicio($this))->assertCreated()->json('id');

        $this->as($this->co->owner)->deleteJson("/api/services/{$nuevo}")->assertOk()->assertJsonMissing(['deactivated' => true]);

        expect(DB::table('services')->where('id', $nuevo)->exists())->toBeFalse();
    });

    it('al desactivarlo avisa cuántas citas por venir tiene', function () {
        $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant, '10:00'))->assertCreated();
        $inactivo = DB::table('statuses')->where('slug', 'inactive')->whereIn('module_id', DB::table('status_modules')->where('name', 'services')->select('id'))->value('id');

        $this->as($this->co->owner)->putJson("/api/services/{$this->servicio->id}", datosServicio($this, ['name' => 'Limpieza facial', 'duration_minutes' => 60, 'price' => 80, 'status_id' => $inactivo]))
            ->assertOk()->assertJsonPath('future_appointments', 1);
    });

    it('el precio puede cambiar por sede y la cita toma el de su sede', function () {
        $norte = $this->branch($this->co, 'Sede Norte');
        $this->professional($this->co, 'Rita Sede Norte', 0, $norte);
        $rita = DB::table('professionals')->where('display_name', 'Rita Sede Norte')->first();

        $this->as($this->co->owner)->putJson("/api/services/{$this->servicio->id}", datosServicio($this, [
            'name' => 'Limpieza facial', 'duration_minutes' => 60, 'price' => 80,
            'branch_prices' => [['branch_id' => $norte->id, 'price' => 95], ['branch_id' => $this->co->branch->id, 'price' => null]],
        ]))->assertOk()->assertJsonPath('branch_prices.0.price', 95);

        $enNorte = $this->book($this->co, $this->clienta, $this->servicio, $rita, $this->at($this->co->tenant, '10:00'), ['branch_id' => $norte->id])->assertCreated();
        $enCentro = $this->book($this->co, $this->customer($this->co->tenant), $this->servicio, $this->ana, $this->at($this->co->tenant, '12:00'))->assertCreated();

        expect(money($enNorte->json('price')))->toBe(95.0)
            ->and(money($enCentro->json('price')))->toBe(80.0);

        $lista = collect($this->as($this->co->owner)->getJson('/api/services')->assertOk()->json())->firstWhere('id', $this->servicio->id);
        expect($lista['branch_prices'])->toHaveCount(1);
    });
});

describe('buscador de la barra superior', function () {
    it('encuentra clientes, citas y servicios', function () {
        $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant, '10:00'))->assertCreated();

        $grupos = collect($this->as($this->co->owner)->getJson('/api/search?q=Paredes')->assertOk()->json('groups'))->keyBy('key');
        expect($grupos->keys()->all())->toContain('customers', 'appointments')
            ->and($grupos['customers']['items'][0]['url'])->toBe("/customers/{$this->clienta->id}")
            ->and($grupos['appointments']['items'][0]['url'])->toStartWith('/agenda?cita=');

        $servicios = collect($this->as($this->co->owner)->getJson('/api/search?q=limpieza')->assertOk()->json('groups'))->keyBy('key');
        expect($servicios['services']['items'][0]['title'])->toBe('Limpieza facial');
    });

    it('no muestra lo que el rol no puede ver', function () {
        $caja = $this->staff($this->co, 'cashier');
        $grupos = collect($this->as($caja)->getJson('/api/search?q=Limpieza')->assertOk()->json('groups'))->pluck('key');

        expect($grupos)->not->toContain('services')->not->toContain('professionals');
    });

    it('el profesional solo encuentra sus citas', function () {
        $beto = $this->professional($this->co, 'Beto Ríos');
        $this->book($this->co, $this->clienta, $this->servicio, $beto, $this->at($this->co->tenant, '10:00'))->assertCreated();

        $grupos = collect($this->as($this->ana->user)->getJson('/api/search?q=Paredes')->assertOk()->json('groups'))->keyBy('key');
        expect($grupos->has('appointments'))->toBeFalse();
    });

    it('no trae nada de otra empresa', function () {
        $otra = $this->company();
        $this->customer($otra->tenant, 'Zoila', 'Ajena');

        expect($this->as($this->co->owner)->getJson('/api/search?q=Ajena')->assertOk()->json('groups'))->toBe([]);
    });
});

describe('selector de sede', function () {
    it('quien ve varias sedes elige una y los listados se filtran', function () {
        $norte = $this->branch($this->co, 'Sede Norte');
        $rita = $this->professional($this->co, 'Rita Norte', 0, $norte);
        $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant, '10:00'))->assertCreated();
        $this->book($this->co, $this->customer($this->co->tenant), $this->servicio, $rita, $this->at($this->co->tenant, '11:00'), ['branch_id' => $norte->id])->assertCreated();
        $fecha = substr($this->at($this->co->tenant), 0, 10);

        $todas = $this->as($this->co->owner)->getJson("/api/appointments?date={$fecha}")->assertOk()->json();
        $solo = $this->as($this->co->owner)->withHeader('X-Branch-Id', $norte->id)->getJson("/api/appointments?date={$fecha}")->assertOk()->json();

        expect($todas)->toHaveCount(2)
            ->and($solo)->toHaveCount(1)
            ->and($solo[0]['branch']['id'])->toBe($norte->id);
    });

    it('una sede de otra empresa no filtra nada', function () {
        $otra = $this->company();
        $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant, '10:00'))->assertCreated();
        $fecha = substr($this->at($this->co->tenant), 0, 10);

        expect($this->as($this->co->owner)->withHeader('X-Branch-Id', $otra->branch->id)->getJson("/api/appointments?date={$fecha}")->assertOk()->json())->toHaveCount(1);
    });

    it('elegir una sede no cambia lo que puede guardar', function () {
        $norte = $this->branch($this->co, 'Sede Norte');

        $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant, '10:00'), ['as' => $this->co->owner])
            ->assertCreated();

        $this->as($this->co->owner)->withHeader('X-Branch-Id', $norte->id)->getJson('/api/user')->assertOk()
            ->assertJsonCount(2, 'branch_choices');
    });

    it('la lista de sucursales muestra todas aunque haya una sede elegida', function () {
        $norte = $this->branch($this->co, 'Sede Norte');

        $this->as($this->co->owner)->withHeader('X-Branch-Id', $norte->id)->getJson('/api/branches')->assertOk()
            ->assertJsonCount(2, 'branches');
    });

    it('recepción de una sede no recibe sedes para elegir', function () {
        $recepcion = $this->staff($this->co, 'reception');

        $this->as($recepcion)->getJson('/api/user')->assertOk()->assertJsonPath('branch_choices', []);
    });
});

describe('rubros del kit', function () {
    it('están las plantillas que faltaban, con su catálogo', function () {
        $slugs = BusinessType::pluck('slug')->all();

        expect($slugs)->toContain('nails', 'tattoo', 'podiatry', 'kids_therapy', 'nutrition', 'grooming')
            ->and(count($slugs))->toBeGreaterThanOrEqual(14);
    });

    it('una empresa de terapia infantil nace con sus servicios, su ficha y niños a cargo', function () {
        $co = $this->company(['business' => 'kids_therapy']);

        expect(DB::table('services')->where('tenant_id', $co->tenant->id)->count())->toBeGreaterThan(0)
            ->and(DB::table('record_templates')->where('tenant_id', $co->tenant->id)->value('name'))->toBe('Evolución de terapia')
            ->and($co->tenant->dependents()['enabled'])->toBeTrue()
            ->and($co->tenant->vocabulary()['pro'])->toBe('Terapeuta');
    });
});

describe('cambiar el horario', function () {
    it('lista las citas por venir que quedan fuera del nuevo horario', function () {
        $cita = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant, '17:00'))->assertCreated()->json('code');

        $ranges = collect(range(0, 6))->map(fn ($day) => ['weekday' => $day, 'starts' => '08:00', 'ends' => '13:00'])->all();
        $respuesta = $this->as($this->co->owner)->putJson("/api/professionals/{$this->ana->id}/schedule", ['branch_id' => $this->co->branch->id, 'ranges' => $ranges])->assertOk();

        expect(collect($respuesta->json('outside'))->pluck('code')->all())->toBe([$cita]);
    });

    it('si las citas siguen dentro, no avisa nada', function () {
        $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant, '10:00'))->assertCreated();

        $ranges = collect(range(0, 6))->map(fn ($day) => ['weekday' => $day, 'starts' => '08:00', 'ends' => '13:00'])->all();
        $this->as($this->co->owner)->putJson("/api/professionals/{$this->ana->id}/schedule", ['branch_id' => $this->co->branch->id, 'ranges' => $ranges])
            ->assertOk()->assertJsonPath('outside', []);
    });
});

describe('agenda del día', function () {
    it('muestra la ocupación de cada profesional', function () {
        $fecha = $this->at($this->co->tenant, '10:00');
        $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $fecha)->assertCreated();

        $dia = $this->as($this->co->owner)->getJson('/api/appointments/day?date=' . substr($fecha, 0, 10) . '&branch_id=' . $this->co->branch->id)->assertOk();
        $ana = collect($dia->json('professionals'))->firstWhere('id', $this->ana->id);

        expect($ana['occupancy']['scheduled_minutes'])->toBe(720)
            ->and($ana['occupancy']['booked_minutes'])->toBe(60)
            ->and($ana['occupancy']['percent'])->toBe(8);
    });
});
