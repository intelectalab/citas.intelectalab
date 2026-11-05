<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company();
    $this->sedeA = $this->co->branch;
    $this->sedeB = $this->branch($this->co, 'Sede Surco');
    $this->servicio = $this->servicePriced($this->co->tenant, 50, 'Corte');
    DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 30]);
    $this->anaA = $this->professional($this->co, 'Ana Torres', 10, $this->sedeA);
    $this->betoB = $this->professional($this->co, 'Beto Ruiz', 10, $this->sedeB);
    $this->clienta = $this->customer($this->co->tenant, 'Lucía', 'Paredes');
    $this->otra = $this->customer($this->co->tenant, 'Marta', 'Quispe');

    $this->citaA = $this->book($this->co, $this->clienta, $this->servicio, $this->anaA, $this->at($this->co->tenant, '10:00'))->assertCreated()->json();
    $this->citaB = $this->book($this->co, $this->otra, $this->servicio, $this->betoB, $this->at($this->co->tenant, '10:00'), ['branch_id' => $this->sedeB->id])->assertCreated()->json();

    $this->ventaEn = function ($sede) {
        $venta = $this->as($this->co->owner)->postJson('/api/pos/sales', ['branch_id' => $sede->id, 'items' => [['type' => 'service', 'id' => $this->servicio->id]]])->assertCreated()->json('sale');
        $this->as($this->co->owner)->postJson("/api/sales/{$venta['id']}/pay", ['method' => 'card', 'amount' => 50, 'reference' => 'X'])->assertOk();

        return $venta;
    };

    $this->dia = $this->workday($this->co->tenant)->format('Y-m-d');
});

describe('recepción', function () {
    beforeEach(function () {
        $this->recepcion = $this->staff($this->co, 'reception', $this->sedeA, 'Rosa Recepción');
    });

    it('agenda en su sede, pero no en otra', function () {
        $this->book($this->co, $this->otra, $this->servicio, $this->anaA, $this->at($this->co->tenant, '15:00'), ['as' => $this->recepcion])->assertCreated();

        $this->book($this->co, $this->clienta, $this->servicio, $this->betoB, $this->at($this->co->tenant, '15:00'), ['as' => $this->recepcion, 'branch_id' => $this->sedeB->id])
            ->assertForbidden();
    });

    it('en la agenda solo ve las citas de su sede', function () {
        $ids = collect($this->as($this->recepcion)->getJson("/api/appointments?date={$this->dia}")->assertOk()->json())->pluck('id');

        expect($ids->all())->toBe([$this->citaA['id']]);
    });

    it('no puede mover ni cancelar citas de otra sede', function () {
        $this->as($this->recepcion)->postJson("/api/appointments/{$this->citaB['id']}/status", ['status' => 'cancelled', 'reason' => 'x'])->assertForbidden();

        expect(DB::table('appointments')->where('id', $this->citaB['id'])->value('status'))->toBe('pending');
    });

    it('cobra, pero no anula ni devuelve dinero', function () {
        $venta = $this->as($this->recepcion)->postJson('/api/pos/sales', ['branch_id' => $this->sedeA->id, 'items' => [['type' => 'service', 'id' => $this->servicio->id]]])->assertCreated()->json('sale');
        $this->as($this->recepcion)->postJson("/api/sales/{$venta['id']}/pay", ['method' => 'card', 'amount' => 50, 'reference' => 'X'])->assertOk();

        $this->as($this->recepcion)->postJson("/api/sales/{$venta['id']}/refunds", ['amount' => 10, 'method' => 'card', 'reason' => 'x'])->assertForbidden();

        $pendiente = $this->as($this->recepcion)->postJson('/api/pos/sales', ['branch_id' => $this->sedeA->id, 'items' => [['type' => 'service', 'id' => $this->servicio->id]]])->json('sale');
        $this->as($this->recepcion)->postJson("/api/sales/{$pendiente['id']}/void", ['reason' => 'x'])->assertForbidden();
    });

    it('no puede sobre-agendar aunque escriba un motivo', function () {
        $this->book($this->co, $this->otra, $this->servicio, $this->anaA, $this->at($this->co->tenant, '20:30'), ['as' => $this->recepcion, 'override_reason' => 'Lo pidió la clienta'])
            ->assertStatus(409);
    });

    it('no crea ni cambia servicios', function () {
        $this->as($this->recepcion)->putJson("/api/services/{$this->servicio->id}", ['name' => 'Otro nombre', 'price' => 1])->assertForbidden();

        expect(DB::table('services')->where('id', $this->servicio->id)->value('name'))->toBe('Corte');
    });
});

describe('caja', function () {
    beforeEach(function () {
        $this->cajera = $this->staff($this->co, 'cashier', $this->sedeA, 'Carla Caja');
    });

    it('ve la agenda pero no crea ni cambia citas', function () {
        $this->as($this->cajera)->getJson("/api/appointments?date={$this->dia}")->assertOk();
        $this->book($this->co, $this->otra, $this->servicio, $this->anaA, $this->at($this->co->tenant, '15:00'), ['as' => $this->cajera])->assertForbidden();
        $this->as($this->cajera)->postJson("/api/appointments/{$this->citaA['id']}/status", ['status' => 'confirmed'])->assertForbidden();
    });

    it('abre su caja y cobra en efectivo', function () {
        $register = $this->as($this->co->owner)->postJson('/api/cash/registers', ['branch_id' => $this->sedeA->id, 'name' => 'Caja 1'])->assertCreated()->json('id');
        $this->as($this->cajera)->postJson("/api/cash/registers/{$register}/open", ['opening_amount' => 20])->assertCreated();

        $venta = $this->as($this->cajera)->postJson('/api/pos/sales', ['branch_id' => $this->sedeA->id, 'items' => [['type' => 'service', 'id' => $this->servicio->id]]])->assertCreated()->json('sale');
        $this->as($this->cajera)->postJson("/api/sales/{$venta['id']}/pay", ['method' => 'cash', 'amount' => 50])->assertOk();
    });

    it('no maneja el turno de caja que abrió otra persona', function () {
        $turno = $this->openCash($this->co, 100);

        $this->as($this->cajera)->postJson("/api/cash/sessions/{$turno['id']}/movements", ['type' => 'withdrawal', 'amount' => 50, 'description' => 'x'])->assertForbidden();
        $this->as($this->cajera)->postJson("/api/cash/sessions/{$turno['id']}/close", ['counted_amount' => 100])->assertForbidden();

        expect(DB::table('cash_sessions')->where('id', $turno['id'])->value('status'))->toBe('open');
    });
});

describe('gerente de sede', function () {
    beforeEach(function () {
        $this->gerente = $this->staff($this->co, 'manager', $this->sedeA, 'Gina Gerente');
    });

    it('devuelve ventas de su sede, pero no de otra', function () {
        $mia = ($this->ventaEn)($this->sedeA);
        $ajena = ($this->ventaEn)($this->sedeB);

        $this->as($this->gerente)->postJson("/api/sales/{$mia['id']}/refunds", ['amount' => 10, 'method' => 'card', 'reason' => 'Reclamo'])->assertOk();
        $this->as($this->gerente)->postJson("/api/sales/{$ajena['id']}/refunds", ['amount' => 10, 'method' => 'card', 'reason' => 'Reclamo'])->assertForbidden();
    });

    it('en ventas solo ve las de su sede', function () {
        $mia = ($this->ventaEn)($this->sedeA);
        ($this->ventaEn)($this->sedeB);

        $ids = collect($this->as($this->gerente)->getJson('/api/sales')->assertOk()->json('sales'))->pluck('id');

        expect($ids->all())->toBe([$mia['id']]);
    });

    it('puede sobre-agendar en su sede con un motivo', function () {
        $this->book($this->co, $this->otra, $this->servicio, $this->anaA, $this->at($this->co->tenant, '20:30'), ['as' => $this->gerente, 'override_reason' => 'Cliente frecuente'])
            ->assertCreated();
    });

    it('en comisiones solo ve las de su sede', function () {
        ($this->ventaEn)($this->sedeA);
        ($this->ventaEn)($this->sedeB);
        DB::table('sale_items')->whereNull('professional_id')->update(['professional_id' => $this->anaA->id]);
        foreach (DB::table('sales')->where('tenant_id', $this->co->tenant->id)->get() as $venta) {
            \App\Support\Commissions::forSale($this->co->tenant, $venta);
        }

        $sedes = collect($this->as($this->gerente)->getJson('/api/commissions')->assertOk()->json('entries'))->pluck('branch_name')->unique()->values();

        expect(DB::table('commission_entries')->where('tenant_id', $this->co->tenant->id)->count())->toBe(2)
            ->and($sedes->all())->toBe([$this->sedeA->name]);
    });
});

describe('profesional', function () {
    it('en la agenda solo ve sus propias citas', function () {
        $otraCita = $this->book($this->co, $this->otra, $this->servicio, $this->professional($this->co, 'Carmen Díaz', 0, $this->sedeA), $this->at($this->co->tenant, '10:00'))->json('id');

        $ids = collect($this->as($this->anaA->user)->getJson("/api/appointments?date={$this->dia}")->assertOk()->json())->pluck('id');

        expect($ids->all())->toBe([$this->citaA['id']])
            ->and($ids)->not->toContain($otraCita);
    });

    it('solo agenda para sí misma', function () {
        $carmen = $this->professional($this->co, 'Carmen Díaz', 0, $this->sedeA);

        $this->book($this->co, $this->otra, $this->servicio, $carmen, $this->at($this->co->tenant, '15:00'), ['as' => $this->anaA->user])->assertForbidden();
        $this->book($this->co, $this->otra, $this->servicio, $this->anaA, $this->at($this->co->tenant, '15:00'), ['as' => $this->anaA->user])->assertCreated();
    });

    it('no cambia la fase de una cita ajena', function () {
        $this->as($this->anaA->user)->postJson("/api/appointments/{$this->citaB['id']}/status", ['status' => 'confirmed'])->assertForbidden();
        $this->as($this->anaA->user)->postJson("/api/appointments/{$this->citaA['id']}/status", ['status' => 'confirmed'])->assertOk();
    });

    it('no entra a la caja ni a la lista de ventas', function () {
        $this->as($this->anaA->user)->getJson('/api/cash')->assertForbidden();
        $this->as($this->anaA->user)->getJson('/api/sales')->assertForbidden();
    });
});

describe('cuentas', function () {
    it('una cuenta desactivada ya no entra', function () {
        $recepcion = $this->staff($this->co, 'reception', $this->sedeA);
        DB::table('users')->where('id', $recepcion->id)->update(['status' => 'inactive']);

        $this->as($recepcion->fresh())->getJson("/api/appointments?date={$this->dia}")->assertUnauthorized();
    });

    it('quien tiene una contraseña temporal debe cambiarla antes de seguir', function () {
        $recepcion = $this->staff($this->co, 'reception', $this->sedeA);
        DB::table('users')->where('id', $recepcion->id)->update(['must_change_password' => true]);

        $this->as($recepcion->fresh())->getJson("/api/appointments?date={$this->dia}")->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    });

    it('un permiso quitado a una persona se respeta aunque su rol lo tenga', function () {
        $recepcion = $this->staff($this->co, 'reception', $this->sedeA);
        $permiso = DB::table('permissions')->where('name', 'agenda.create')->value('id');
        DB::table('user_permissions')->insert(['tenant_id' => $this->co->tenant->id, 'user_id' => $recepcion->id, 'permission_id' => $permiso, 'granted' => false]);

        $this->book($this->co, $this->otra, $this->servicio, $this->anaA, $this->at($this->co->tenant, '15:00'), ['as' => $recepcion])->assertForbidden();
    });
});

describe('cada empresa ve solo lo suyo', function () {
    beforeEach(function () {
        $this->otraEmpresa = $this->company();
    });

    it('no ve, cambia ni mueve citas de otra empresa', function () {
        $this->as($this->otraEmpresa->owner)->getJson("/api/appointments/{$this->citaA['id']}")->assertNotFound();
        $this->as($this->otraEmpresa->owner)->postJson("/api/appointments/{$this->citaA['id']}/status", ['status' => 'cancelled', 'reason' => 'x'])->assertNotFound();
        $this->as($this->otraEmpresa->owner)->postJson("/api/appointments/{$this->citaA['id']}/reschedule", ['starts_at' => $this->at($this->co->tenant, '16:00')])->assertNotFound();

        expect(DB::table('appointments')->where('id', $this->citaA['id'])->value('status'))->toBe('pending');
    });

    it('no puede agendar con clientes, servicios o profesionales de otra empresa', function () {
        $suya = $this->professional($this->otraEmpresa, 'Diego Paz');
        $suyo = $this->servicePriced($this->otraEmpresa->tenant, 10);

        $this->book($this->otraEmpresa, $this->clienta, $suyo, $suya, $this->at($this->co->tenant, '15:00'))->assertNotFound();
        $this->book($this->otraEmpresa, $this->customer($this->otraEmpresa->tenant), $this->servicio, $suya, $this->at($this->co->tenant, '15:00'))->assertNotFound();
        $this->book($this->otraEmpresa, $this->customer($this->otraEmpresa->tenant), $suyo, $this->anaA, $this->at($this->co->tenant, '15:00'))->assertNotFound();
    });

    it('la agenda del día no muestra citas de otra empresa', function () {
        expect($this->as($this->otraEmpresa->owner)->getJson("/api/appointments?date={$this->dia}")->assertOk()->json())->toBe([]);
    });

    it('no ve los clientes de otra empresa', function () {
        $this->as($this->otraEmpresa->owner)->getJson("/api/customers/{$this->clienta->id}")->assertNotFound();

        $ids = collect($this->as($this->otraEmpresa->owner)->getJson('/api/customers')->assertOk()->json('data'))->pluck('id');
        expect($ids)->not->toContain($this->clienta->id);
    });
});
