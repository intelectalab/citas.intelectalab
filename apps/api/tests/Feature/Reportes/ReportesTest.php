<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company(['modules_off' => []]);
    $this->servicio = $this->servicePriced($this->co->tenant, 100, 'Corte reporte');
    $this->barato = $this->servicePriced($this->co->tenant, 50, 'Lavado reporte');
    $this->clienta = $this->customer($this->co->tenant);

    $this->reporte = fn (array $query = [], $user = null) => $this->as($user ?? $this->co->owner)->getJson('/api/reports?' . http_build_query($query));

    $this->cobrar = function (object $servicio, string $method, ?string $branchId = null) {
        $venta = $this->as($this->co->owner)->postJson('/api/pos/sales', [
            'branch_id' => $branchId ?? $this->co->branch->id, 'customer_id' => $this->clienta->id,
            'items' => [['type' => 'service', 'id' => $servicio->id, 'quantity' => 1]],
        ])->assertCreated()->json('sale');
        $this->pay($this->co, $venta['id'], ['method' => $method, 'amount' => $venta['total']])->assertOk();

        return $venta;
    };

    $this->citaPasada = function (object $pro, string $status, int $diasAtras = 1) {
        static $hora = 9;
        $hora++;
        $id = $this->book($this->co, $this->clienta, $this->servicio, $pro, $this->at($this->co->tenant, sprintf('%02d:00', $hora)))->assertCreated()->json('id');
        $inicio = now()->subDays($diasAtras)->setTime($hora, 0);
        DB::table('appointments')->where('id', $id)->update([
            'status' => $status, 'starts_at' => $inicio, 'ends_at' => $inicio->copy()->addMinutes(30),
            'completed_at' => $status === 'completed' ? $inicio->copy()->addMinutes(30) : null,
        ]);

        return $id;
    };
});

it('ventas: suma lo cobrado, cuenta solo ventas pagadas y separa por medio de pago', function () {
    $this->openCash($this->co);
    ($this->cobrar)($this->servicio, 'cash');
    ($this->cobrar)($this->barato, 'card');
    $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id, 'quantity' => 1]]);

    $datos = ($this->reporte)()->assertOk()->json();
    $metodos = collect($datos['data']['detail']['by_method'])->keyBy('key');

    expect($datos['tab'])->toBe('sales')
        ->and($datos['money'])->toBeTrue()
        ->and(money($datos['data']['summary']['income']))->toBe(150.0)
        ->and($datos['data']['summary']['sales'])->toBe(2)
        ->and(money($datos['data']['summary']['ticket']))->toBe(75.0)
        ->and(money($metodos['cash']['value']))->toBe(100.0)
        ->and(money($metodos['card']['value']))->toBe(50.0);
});

it('las devoluciones se restan de lo cobrado y se muestran aparte', function () {
    $this->openCash($this->co);
    $venta = ($this->cobrar)($this->servicio, 'cash');
    $this->as($this->co->owner)->postJson("/api/sales/{$venta['id']}/refunds", ['amount' => 40, 'method' => 'cash', 'reason' => 'Parcial'])->assertOk();

    $resumen = ($this->reporte)()->json('data.summary');

    expect(money($resumen['refunds']))->toBe(40.0)
        ->and(money($resumen['income']))->toBe(60.0);
});

it('filtra por sede y la gerencia de una sede solo ve la suya', function () {
    $sede2 = $this->branch($this->co);
    ($this->cobrar)($this->servicio, 'card');
    ($this->cobrar)($this->barato, 'card', $sede2->id);

    expect(money(($this->reporte)()->json('data.summary.income')))->toBe(150.0)
        ->and(money(($this->reporte)(['branch_id' => $sede2->id])->json('data.summary.income')))->toBe(50.0);

    $gerente = $this->staff($this->co, 'manager');
    $suyo = ($this->reporte)(['branch_id' => $sede2->id], $gerente)->assertOk()->json();

    expect($suyo['branch_locked'])->toBeTrue()
        ->and($suyo['branch_id'])->toBe($this->co->branch->id)
        ->and(money($suyo['data']['summary']['income']))->toBe(100.0);
});

it('quien no tiene permiso de ver dinero recibe los montos vacíos', function () {
    ($this->cobrar)($this->servicio, 'card');
    $gerente = $this->staff($this->co, 'manager');
    DB::table('user_permissions')->insert([
        'tenant_id' => $this->co->tenant->id, 'user_id' => $gerente->id, 'granted' => false,
        'permission_id' => DB::table('permissions')->where('name', 'reports.money')->value('id'),
    ]);

    $datos = ($this->reporte)([], $gerente)->assertOk()->json();

    expect($datos['money'])->toBeFalse()
        ->and($datos['data']['summary']['income'])->toBeNull()
        ->and($datos['data']['summary']['sales'])->toBe(1)
        ->and($datos['data']['detail'])->toBeNull();
});

it('citas: cuenta atendidas, cancelaciones y faltas del periodo', function () {
    $pro = $this->professional($this->co);
    ($this->citaPasada)($pro, 'completed');
    ($this->citaPasada)($pro, 'completed');
    ($this->citaPasada)($pro, 'cancelled');
    ($this->citaPasada)($pro, 'no_show');
    ($this->citaPasada)($pro, 'completed', 45);

    $resumen = ($this->reporte)(['tab' => 'appointments'])->assertOk()->json('data.summary');

    expect($resumen['appointments'])->toBe(3)
        ->and($resumen['completed'])->toBe(2)
        ->and($resumen['cancelled'])->toBe(25)
        ->and($resumen['no_show'])->toBe(25);

    expect(($this->reporte)(['tab' => 'appointments', 'period' => '90'])->json('data.summary.completed'))->toBe(3);
});

it('el profesional solo ve sus propios números', function () {
    $ana = $this->professional($this->co, 'Ana Reporte');
    $beto = $this->professional($this->co, 'Beto Reporte');
    ($this->citaPasada)($ana, 'completed');
    ($this->citaPasada)($beto, 'completed');
    ($this->citaPasada)($beto, 'completed');

    $datos = ($this->reporte)(['tab' => 'sales'], $ana->user)->assertOk()->json();

    expect($datos['tab'])->toBe('mine')
        ->and($datos['own_only'])->toBeTrue()
        ->and(collect($datos['tabs'])->pluck('key')->all())->toBe(['mine'])
        ->and($datos['data']['summary']['completed'])->toBe(1);

    $sinFicha = $this->staff($this->co, 'professional');
    ($this->reporte)([], $sinFicha)->assertForbidden();
    ($this->reporte)([], $this->staff($this->co, 'reception'))->assertForbidden();
});

it('las pestañas avanzadas quedan bloqueadas sin el módulo de reportes avanzados', function () {
    DB::table('tenant_settings')->where('tenant_id', $this->co->tenant->id)->update(['modules_off' => json_encode(['reports_adv'])]);

    $datos = ($this->reporte)(['tab' => 'customers'])->assertOk()->json();

    expect($datos['locked'])->toBeTrue()
        ->and($datos['data'])->toBeNull()
        ->and(($this->reporte)(['tab' => 'sales'])->json('data.previous'))->toBeNull();
});

it('valida el periodo elegido a mano', function () {
    ($this->reporte)(['period' => 'custom', 'from' => '2026-11-10', 'to' => '2026-11-01'])->assertStatus(422)->assertJsonValidationErrors('to');
    ($this->reporte)(['period' => 'custom', 'from' => '2025-01-01', 'to' => '2026-06-01'])->assertStatus(422)->assertJsonValidationErrors('to');
    ($this->reporte)(['period' => 'custom'])->assertStatus(422)->assertJsonValidationErrors(['from', 'to']);

    $rango = ($this->reporte)(['period' => 'custom', 'from' => '2026-11-01', 'to' => '2026-11-10'])->assertOk()->json('range');
    expect($rango['days'])->toBe(10)->and($rango['prev_to'])->toBe('2026-10-31');
});

it('no mezcla ventas ni sedes de otra empresa', function () {
    $otra = $this->company(['modules_off' => []]);
    $servicioAjeno = $this->servicePriced($otra->tenant, 999, 'Ajeno');
    $ventaAjena = $this->as($otra->owner)->postJson('/api/pos/sales', ['branch_id' => $otra->branch->id, 'items' => [['type' => 'service', 'id' => $servicioAjeno->id, 'quantity' => 1]]])->assertCreated()->json('sale');
    $this->pay($otra, $ventaAjena['id'], ['method' => 'card', 'amount' => 999])->assertOk();
    ($this->cobrar)($this->servicio, 'card');

    $datos = ($this->reporte)(['branch_id' => $otra->branch->id])->assertOk()->json();

    expect(money($datos['data']['summary']['income']))->toBe(100.0)
        ->and($datos['branch_id'])->toBeNull()
        ->and(collect($datos['branches'])->pluck('id'))->not->toContain($otra->branch->id);
});
