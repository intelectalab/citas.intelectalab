<?php

use App\Support\Commissions;
use App\Support\Coupons;
use App\Support\Currency;
use App\Support\Inventory;
use App\Support\Loyalty;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->co = $this->company(['modules_off' => []]);
    $this->clienta = $this->customer($this->co->tenant);
    $this->openCash($this->co);

    $this->producto = function (float $precio = 30, float $stock = 5) {
        $this->as($this->co->owner)->postJson('/api/products', ['name' => 'Shampoo ' . Str::random(4), 'price' => $precio, 'cost' => 10, 'kind' => 'both'])->assertCreated();
        $producto = DB::table('products')->where('tenant_id', $this->co->tenant->id)->orderByDesc('created_at')->first();
        $this->as($this->co->owner)->getJson('/api/inventory')->assertOk();
        $almacen = DB::table('warehouses')->where('tenant_id', $this->co->tenant->id)->where('branch_id', $this->co->branch->id)->value('id');

        if ($stock > 0) {
            $this->as($this->co->owner)->postJson('/api/inventory/movements', ['mode' => 'entry', 'warehouse_id' => $almacen, 'product_id' => $producto->id, 'quantity' => $stock])->assertCreated();
        }

        return $producto;
    };
    $this->stock = fn (object $producto) => (float) DB::table('stock_balances')->where('product_id', $producto->id)->sum('quantity');
    $this->devolver = fn (string $ventaId, float $monto, array $extra = []) => $this->as($this->co->owner)->postJson("/api/sales/{$ventaId}/refunds", $extra + ['amount' => $monto, 'method' => 'cash', 'reason' => 'Lo devolvió']);
    $this->puntos = fn () => Loyalty::customerPoints($this->co->tenant->id, $this->clienta->id);
});

describe('devolución parcial (M13)', function () {
    it('repone al stock solo lo que el cliente devolvió y al terminar repone el resto sin duplicar', function () {
        $producto = ($this->producto)(30, 5);
        $venta = $this->sell($this->co, [['type' => 'product', 'id' => $producto->id, 'quantity' => 2]], ['customer_id' => $this->clienta->id]);
        $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => $venta['total']])->assertOk();

        expect(($this->stock)($producto))->toBe(3.0);

        $ficha = $this->as($this->co->owner)->getJson("/api/sales/{$venta['id']}")->assertOk()->json('refund_stock');
        expect($ficha)->toHaveCount(1)->and(money($ficha[0]['left']))->toBe(2.0);

        ($this->devolver)($venta['id'], 30, ['restock' => [['product_id' => $producto->id, 'quantity' => 1]]])->assertOk();
        expect(($this->stock)($producto))->toBe(4.0);

        ($this->devolver)($venta['id'], 30)->assertOk();
        expect(($this->stock)($producto))->toBe(5.0)
            ->and($this->sale($venta['id'])->status)->toBe('refunded');
    });

    it('sin productos marcados no toca el stock, y no deja reponer más de lo vendido', function () {
        $producto = ($this->producto)(30, 5);
        $venta = $this->sell($this->co, [['type' => 'product', 'id' => $producto->id, 'quantity' => 2]]);
        $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => $venta['total']])->assertOk();

        ($this->devolver)($venta['id'], 10, ['restock' => [['product_id' => $producto->id, 'quantity' => 3]]])->assertStatus(422)->assertJsonValidationErrors('restock');
        ($this->devolver)($venta['id'], 10)->assertOk();

        expect(($this->stock)($producto))->toBe(3.0);
    });

    it('una devolución parcial descuenta la parte de los puntos ganados, y la total el resto', function () {
        $servicio = $this->servicePriced($this->co->tenant, 100, 'Corte con puntos');
        $venta = $this->sell($this->co, [['type' => 'service', 'id' => $servicio->id]], ['customer_id' => $this->clienta->id]);
        $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => 100])->assertOk();

        expect(($this->puntos)())->toBe(10);

        ($this->devolver)($venta['id'], 50)->assertOk();
        expect(($this->puntos)())->toBe(5);

        ($this->devolver)($venta['id'], 50)->assertOk();
        expect(($this->puntos)())->toBe(0)
            ->and((int) DB::table('loyalty_transactions')->where('sale_id', $venta['id'])->where('type', 'reversal')->sum('points'))->toBe(-10);
    });

    it('la propina no da puntos', function () {
        $this->setRules($this->co->tenant, ['pos.tips_enabled' => true]);
        $servicio = $this->servicePriced($this->co->tenant, 100, 'Corte con propina');
        $venta = $this->sell($this->co, [['type' => 'service', 'id' => $servicio->id]], ['customer_id' => $this->clienta->id, 'tip' => 20]);
        expect(money($venta['total']))->toBe(120.0);

        $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => 120])->assertOk();

        expect(($this->puntos)())->toBe(10);
    });
});

it('Inicio: los ingresos de hoy son lo cobrado menos lo devuelto, no el precio de las citas (M14)', function () {
    $servicio = $this->servicePriced($this->co->tenant, 100, 'Corte para Inicio');
    $venta = $this->sell($this->co, [['type' => 'service', 'id' => $servicio->id]]);
    $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => 100])->assertOk();
    $barato = $this->servicePriced($this->co->tenant, 40, 'Cejas para Inicio');
    $otra = $this->sell($this->co, [['type' => 'service', 'id' => $barato->id]]);
    $this->pay($this->co, $otra['id'], ['method' => 'cash', 'amount' => 40])->assertOk();
    ($this->devolver)($venta['id'], 30)->assertOk();

    expect(money($this->as($this->co->owner)->getJson('/api/dashboard')->assertOk()->json('today.value')))->toBe(110.0);
});

describe('caja a la vez (M15)', function () {
    it('un cupón que ya agotó sus usos no se vuelve a canjear aunque se haya leído antes', function () {
        $id = (string) Str::uuid();
        DB::table('coupons')->insert([
            'id' => $id, 'tenant_id' => $this->co->tenant->id, 'code' => 'UNAVEZ', 'discount_type' => 'amount', 'discount_value' => 10,
            'max_uses' => 1, 'used_count' => 0, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $leido = DB::table('coupons')->where('id', $id)->first();
        $servicio = $this->servicePriced($this->co->tenant, 50, 'Corte con cupón');
        $primera = $this->sell($this->co, [['type' => 'service', 'id' => $servicio->id]]);
        $segunda = $this->sell($this->co, [['type' => 'service', 'id' => $servicio->id]]);

        Coupons::redeem($leido, $primera['id'], null, 10);

        expect(fn () => Coupons::redeem($leido, $segunda['id'], null, 10))->toThrow(ValidationException::class)
            ->and((int) DB::table('coupons')->where('id', $id)->value('used_count'))->toBe(1)
            ->and(DB::table('coupon_redemptions')->where('coupon_id', $id)->count())->toBe(1);
    });

    it('al cobrar, el descuento del stock revisa el saldo con la fila bloqueada y no lo deja en negativo', function () {
        $producto = ($this->producto)(30, 1);
        $venta = $this->sell($this->co, [['type' => 'product', 'id' => $producto->id, 'quantity' => 1]]);
        $otra = $this->sell($this->co, [['type' => 'product', 'id' => $producto->id, 'quantity' => 1]]);
        $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => $venta['total']])->assertOk();

        DB::table('sales')->where('id', $otra['id'])->update(['status' => 'paid']);

        expect(fn () => Inventory::deductForSale($this->co->tenant, $this->sale($otra['id']), $this->co->owner, strict: true))->toThrow(ValidationException::class)
            ->and(($this->stock)($producto))->toBe(0.0);
    });
});

describe('gravedad baja', function () {
    it('el cierre de caja usa la moneda de la empresa al pedir que se explique la diferencia', function () {
        $this->setRules($this->co->tenant, ['currency.code' => 'USD']);
        $turno = DB::table('cash_sessions')->where('tenant_id', $this->co->tenant->id)->where('status', 'open')->first();

        $mensaje = $this->as($this->co->owner)->postJson("/api/cash/sessions/{$turno->id}/close", ['counted_amount' => 50])
            ->assertStatus(422)->json('errors.notes.0');

        expect($mensaje)->toContain(Currency::format(50, 'USD'))->not->toContain('S/');
    });

    it('caja, cola sin cita, kiosko, presupuestos y Google muestran el precio de la sede', function () {
        $servicio = $this->servicePriced($this->co->tenant, 40, 'Corte por sede');
        DB::table('service_branch_prices')->insert(['tenant_id' => $this->co->tenant->id, 'service_id' => $servicio->id, 'branch_id' => $this->co->branch->id, 'price' => 30]);
        DB::table('services')->where('id', $servicio->id)->update(['online_booking' => true]);
        $this->professional($this->co, 'Ana Sede');

        $pos = collect($this->as($this->co->owner)->getJson('/api/pos?branch_id=' . $this->co->branch->id)->assertOk()->json('services'))->firstWhere('id', $servicio->id);
        $cola = collect($this->as($this->co->owner)->getJson('/api/walkins')->assertOk()->json('context.services'))->firstWhere('id', $servicio->id);
        $kiosko = collect($this->getJson("/api/kiosk/{$this->co->tenant->code}?branch_id={$this->co->branch->id}")->assertOk()->json('services'))->firstWhere('id', $servicio->id);
        $presupuestos = collect($this->as($this->co->owner)->getJson('/api/quotes')->assertOk()->json('services'))->firstWhere('id', $servicio->id);
        $google = collect(\App\Support\GoogleReserve::services($this->co->tenant))->firstWhere('service_id', $servicio->id);

        expect(money($pos['price']))->toBe(30.0)
            ->and(money($cola['branch_prices'][$this->co->branch->id]))->toBe(30.0)
            ->and($kiosko['price_label'])->toBe(Currency::format(30, Currency::of($this->co->tenant)))
            ->and(money($presupuestos['branch_prices'][$this->co->branch->id]))->toBe(30.0)
            ->and(money($google['merchant_prices'][0]['amount']))->toBe(30.0);
    });

    it('a Google no se mandan los servicios apagados', function () {
        $servicio = $this->servicePriced($this->co->tenant, 40, 'Servicio apagado');
        $inactivo = DB::table('statuses')->where('slug', 'inactive')->whereIn('module_id', DB::table('status_modules')->where('name', 'services')->select('id'))->value('id');
        DB::table('services')->where('id', $servicio->id)->update(['online_booking' => true, 'status_id' => $inactivo]);

        expect(collect(\App\Support\GoogleReserve::services($this->co->tenant))->pluck('service_id'))->not->toContain($servicio->id);
    });

    it('la comisión por sesión de paquete con base bruta usa lo pagado por sesión, no el precio de lista', function () {
        $this->setRules($this->co->tenant, ['commission.base' => 'gross', 'commission.on_package_use' => true]);
        $servicio = $this->servicePriced($this->co->tenant, 100, 'Masaje de paquete');
        $ana = $this->professional($this->co, 'Ana Paquete', 10);
        $plan = $this->as($this->co->owner)->postJson('/api/package-plans', [
            'name' => 'Cinco masajes', 'price' => 300, 'validity_days' => 90,
            'booking_mode' => 'free', 'frequency' => 'weekly', 'professional_mode' => 'any',
            'late_policy' => 'lose', 'refund_policy' => 'unused_normal',
            'items' => [['service_id' => $servicio->id, 'sessions' => 5]],
        ])->assertCreated()->json();
        $venta = $this->sell($this->co, [['type' => 'package', 'id' => $plan['id']]], ['customer_id' => $this->clienta->id]);
        $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => $venta['total']])->assertOk();
        $paquete = DB::table('customer_packages')->where('sale_id', $venta['id'])->first();

        $usoId = (string) Str::uuid();
        DB::table('package_usages')->insert([
            'id' => $usoId, 'tenant_id' => $this->co->tenant->id, 'customer_package_id' => $paquete->id, 'service_id' => $servicio->id,
            'professional_id' => $ana->id, 'sessions' => 1, 'kind' => 'attended', 'used_at' => now(),
        ]);

        Commissions::forUsage($this->co->tenant, DB::table('package_usages')->where('id', $usoId)->first());
        $fila = DB::table('commission_entries')->where('package_usage_id', $usoId)->first();

        expect(money($fila->base))->toBe(60.0)
            ->and(money($fila->amount))->toBe(6.0);
    });

    it('la lista de citas avisa cuando se corta', function () {
        $servicio = $this->servicePriced($this->co->tenant, 40, 'Corte en lote');
        $ana = $this->professional($this->co, 'Ana Lote');
        $this->book($this->co, $this->clienta, $servicio, $ana, $this->at($this->co->tenant, '10:00', 2))->assertCreated();
        $base = DB::table('appointments')->where('tenant_id', $this->co->tenant->id)->first();

        $filas = collect(range(1, 500))->map(fn ($i) => array_merge((array) $base, [
            'id' => (string) Str::uuid(), 'code' => 'LOTE-' . $i, 'status' => 'cancelled',
        ]))->all();
        foreach (array_chunk($filas, 100) as $parte) {
            DB::table('appointments')->insert($parte);
        }

        $dia = $this->workday($this->co->tenant, 2)->format('Y-m-d');
        $respuesta = $this->as($this->co->owner)->getJson("/api/appointments?date={$dia}")->assertOk();

        expect($respuesta->json())->toHaveCount(500)
            ->and($respuesta->headers->get('X-Rows-Cut'))->toBe('500');
    });
});
