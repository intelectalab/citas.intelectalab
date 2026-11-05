<?php

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company();
    $this->servicio = $this->servicePriced($this->co->tenant, 118, 'Color de prueba');
    $this->ana = $this->professional($this->co, 'Ana Torres', 30);
    $this->beto = $this->professional($this->co, 'Beto Ruiz', 30);

    $this->regla = fn (array $datos) => $this->as($this->co->owner)->postJson('/api/commission-rules', $datos);

    $this->venderCobrar = function (array $lineas) {
        $venta = $this->sell($this->co, $lineas);
        $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => $venta['total']])->assertOk();

        return $venta;
    };

    $this->comisiones = fn (string $ventaId) => DB::table('commission_entries')->where('sale_id', $ventaId)->orderBy('created_at')->get();
});

describe('qué regla se aplica', function () {
    it('sin reglas, usa el porcentaje de la persona sobre el monto sin IGV', function () {
        $venta = ($this->venderCobrar)([['type' => 'service', 'id' => $this->servicio->id, 'professional_id' => $this->ana->id]]);

        $fila = ($this->comisiones)($venta['id'])->first();

        expect(money($fila->base))->toBe(100.0)
            ->and(money($fila->amount))->toBe(30.0)
            ->and($fila->rule_name)->toBe('Su porcentaje')
            ->and($fila->status)->toBe('calculated');
    });

    it('la comisión del servicio, para todos, manda sobre el porcentaje de la persona', function () {
        ($this->regla)(['type' => 'service', 'service_id' => $this->servicio->id, 'percent' => 40])->assertCreated();

        $venta = ($this->venderCobrar)([['type' => 'service', 'id' => $this->servicio->id, 'professional_id' => $this->ana->id]]);

        expect(money(($this->comisiones)($venta['id'])->first()->amount))->toBe(40.0);
    });

    it('la excepción de una persona manda sobre la del servicio, y solo para ella', function () {
        ($this->regla)(['type' => 'service', 'service_id' => $this->servicio->id, 'percent' => 40])->assertCreated();
        ($this->regla)(['type' => 'exception', 'service_id' => $this->servicio->id, 'professional_id' => $this->ana->id, 'percent' => 50, 'fixed' => 5])->assertCreated();

        $venta = ($this->venderCobrar)([
            ['type' => 'service', 'id' => $this->servicio->id, 'professional_id' => $this->ana->id],
            ['type' => 'service', 'id' => $this->servicio->id, 'professional_id' => $this->beto->id],
        ]);

        $filas = ($this->comisiones)($venta['id'])->keyBy('professional_id');

        expect(money($filas[$this->ana->id]->amount))->toBe(55.0)
            ->and($filas[$this->ana->id]->rule_name)->toBe('Excepción')
            ->and(money($filas[$this->beto->id]->amount))->toBe(40.0);
    });

    it('el monto fijo se paga por cada unidad vendida', function () {
        ($this->regla)(['type' => 'service', 'service_id' => $this->servicio->id, 'fixed' => 12])->assertCreated();

        $venta = ($this->venderCobrar)([['type' => 'service', 'id' => $this->servicio->id, 'quantity' => 3, 'professional_id' => $this->ana->id]]);

        expect(money(($this->comisiones)($venta['id'])->first()->amount))->toBe(36.0);
    });

    it('si la empresa calcula sobre el precio con IGV, usa el precio completo', function () {
        $this->setRules($this->co->tenant, ['commission.base' => 'gross']);

        $venta = ($this->venderCobrar)([['type' => 'service', 'id' => $this->servicio->id, 'professional_id' => $this->ana->id]]);

        expect(money(($this->comisiones)($venta['id'])->first()->amount))->toBe(35.4);
    });

    it('un descuento baja la base de la comisión', function () {
        DB::table('coupons')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'code' => 'MITAD',
            'discount_type' => 'percent', 'discount_value' => 50, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id, 'professional_id' => $this->ana->id]], ['coupon_code' => 'MITAD']);
        $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => $venta['total']])->assertOk();

        expect(money(($this->comisiones)($venta['id'])->first()->amount))->toBe(15.0);
    });

    it('una línea sin profesional no genera comisión', function () {
        $venta = ($this->venderCobrar)([
            ['type' => 'service', 'id' => $this->servicio->id, 'professional_id' => $this->ana->id],
            ['type' => 'service', 'id' => $this->servicio->id],
        ]);

        expect(($this->comisiones)($venta['id']))->toHaveCount(1);
    });

    it('no hay comisión hasta que la venta esté pagada', function () {
        $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id, 'professional_id' => $this->ana->id]]);

        expect(($this->comisiones)($venta['id']))->toHaveCount(0);
    });
});

describe('reglas', function () {
    it('no deja crear dos reglas para lo mismo', function () {
        ($this->regla)(['type' => 'service', 'service_id' => $this->servicio->id, 'percent' => 40])->assertCreated();

        ($this->regla)(['type' => 'service', 'service_id' => $this->servicio->id, 'percent' => 10])
            ->assertStatus(422)
            ->assertJsonPath('code', 'COMMISSION_RULE_EXISTS');
    });

    it('pide un porcentaje o un monto fijo', function () {
        ($this->regla)(['type' => 'service', 'service_id' => $this->servicio->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('percent');
    });

    it('no acepta servicios ni personas de otra empresa', function () {
        $otra = $this->company();
        $suyo = $this->servicePriced($otra->tenant, 10);

        ($this->regla)(['type' => 'service', 'service_id' => $suyo->id, 'percent' => 10])
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_id');
    });
});

describe('devoluciones', function () {
    it('devolver la mitad descuenta la mitad de la comisión', function () {
        $venta = ($this->venderCobrar)([['type' => 'service', 'id' => $this->servicio->id, 'professional_id' => $this->ana->id]]);

        $this->as($this->co->owner)->postJson("/api/sales/{$venta['id']}/refunds", ['amount' => 59, 'method' => 'card', 'reason' => 'Retoque'])->assertOk();

        $filas = ($this->comisiones)($venta['id']);

        expect(money($filas->where('source', 'refund')->sum('amount')))->toBe(-15.0)
            ->and(money($filas->sum('amount')))->toBe(15.0);
    });

    it('devolver todo deja la comisión en cero, aunque se devuelva en dos partes', function () {
        $venta = ($this->venderCobrar)([['type' => 'service', 'id' => $this->servicio->id, 'professional_id' => $this->ana->id]]);
        $url = "/api/sales/{$venta['id']}/refunds";

        $this->as($this->co->owner)->postJson($url, ['amount' => 59, 'method' => 'card', 'reason' => 'Primera parte'])->assertOk();
        $this->as($this->co->owner)->postJson($url, ['amount' => 59, 'method' => 'card', 'reason' => 'Segunda parte'])->assertOk();

        expect(money(($this->comisiones)($venta['id'])->sum('amount')))->toBe(0.0);
    });
});

describe('liquidación', function () {
    beforeEach(function () {
        $this->venta = ($this->venderCobrar)([
            ['type' => 'service', 'id' => $this->servicio->id, 'professional_id' => $this->ana->id],
            ['type' => 'service', 'id' => $this->servicio->id, 'professional_id' => $this->beto->id],
        ]);
        $this->ids = ($this->comisiones)($this->venta['id'])->pluck('id')->all();
    });

    it('muestra el total del mes por profesional', function () {
        $datos = $this->as($this->co->owner)->getJson('/api/commissions')->assertOk()->json();

        expect(money($datos['summary']['total']))->toBe(60.0)
            ->and($datos['by_professional'])->toHaveCount(2);
    });

    it('primero se aprueba y después se paga', function () {
        $url = '/api/commissions/transition';

        $this->as($this->co->owner)->postJson($url, ['ids' => $this->ids, 'to' => 'paid'])->assertStatus(422);
        $this->as($this->co->owner)->postJson($url, ['ids' => [$this->ids[0]], 'to' => 'approved'])->assertOk()->assertJsonPath('updated', 1);
        $this->as($this->co->owner)->postJson($url, ['ids' => $this->ids, 'to' => 'paid'])->assertOk()->assertJsonPath('updated', 1)->assertJsonPath('skipped', 1);

        expect(DB::table('commission_entries')->whereIn('id', $this->ids)->pluck('status')->sort()->values()->all())->toBe(['calculated', 'paid']);
    });

    it('recalcular el mes no toca lo ya pagado', function () {
        $this->as($this->co->owner)->postJson('/api/commissions/transition', ['ids' => [$this->ids[0]], 'to' => 'approved'])->assertOk();
        $this->as($this->co->owner)->postJson('/api/commissions/transition', ['ids' => [$this->ids[0]], 'to' => 'paid'])->assertOk();
        ($this->regla)(['type' => 'service', 'service_id' => $this->servicio->id, 'percent' => 50])->assertCreated();

        $this->as($this->co->owner)->postJson('/api/commissions/recalculate', ['period' => \App\Support\Commissions::period($this->co->tenant)])->assertOk();

        $pagada = DB::table('commission_entries')->where('id', $this->ids[0])->first();
        $otra = DB::table('commission_entries')->where('sale_id', $this->venta['id'])->where('status', '!=', 'paid')->first();

        expect($pagada->status)->toBe('paid')
            ->and(money($pagada->amount))->toBe(30.0)
            ->and(money($otra->amount))->toBe(50.0);
    });

    it('cada profesional ve solo sus comisiones y no puede aprobarlas ni crear reglas', function () {
        $datos = $this->as($this->ana->user)->getJson('/api/commissions')->assertOk()->json();

        expect(collect($datos['entries'])->pluck('professional.id')->unique()->all())->toBe([$this->ana->id]);

        $this->as($this->ana->user)->postJson('/api/commissions/transition', ['ids' => $this->ids, 'to' => 'approved'])->assertForbidden();
        $this->as($this->ana->user)->postJson('/api/commission-rules', ['type' => 'service', 'service_id' => $this->servicio->id, 'percent' => 99])->assertForbidden();
    });

    it('otra empresa no puede aprobar ni ver estas comisiones', function () {
        $otra = $this->company();

        $this->as($otra->owner)->postJson('/api/commissions/transition', ['ids' => $this->ids, 'to' => 'approved'])->assertStatus(422);
        expect($this->as($otra->owner)->getJson('/api/commissions')->json('entries'))->toBe([]);
        expect(DB::table('commission_entries')->whereIn('id', $this->ids)->where('status', 'calculated')->count())->toBe(2);
    });
});

it('en los rubros de salud, Comisiones empieza apagado', function () {
    $clinica = $this->company(['business' => 'medical']);

    $this->as($clinica->owner)->getJson('/api/commissions')->assertForbidden();
});
