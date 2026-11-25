<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->co = $this->company();
    $this->categoria = categoriaDeComision($this->co->tenant, 'Color');
    $this->servicio = $this->servicePriced($this->co->tenant, 118, 'Tinte completo');
    DB::table('services')->where('id', $this->servicio->id)->update(['category_id' => $this->categoria]);
    $this->ana = $this->professional($this->co, 'Ana Torres', 30);

    $this->regla = fn (array $datos) => $this->as($this->co->owner)->postJson('/api/commission-rules', $datos);

    $this->comisionDe = function () {
        $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id, 'professional_id' => $this->ana->id]]);
        $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => $venta['total']])->assertOk();

        return DB::table('commission_entries')->where('sale_id', $venta['id'])->first();
    };
});

function categoriaDeComision(object $tenant, string $nombre): string
{
    $id = (string) Str::uuid();
    DB::table('service_categories')->insert(['id' => $id, 'tenant_id' => $tenant->id, 'name' => $nombre, 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

describe('orden al calcular', function () {
    it('la comisión de la categoría manda sobre el porcentaje de la persona', function () {
        ($this->regla)(['type' => 'category', 'category_id' => $this->categoria, 'percent' => 35])->assertCreated()->assertJsonPath('type', 'category')->assertJsonPath('category', 'Color');

        $fila = ($this->comisionDe)();

        expect(money($fila->amount))->toBe(35.0)
            ->and($fila->rule_name)->toBe('Comisión de la categoría');
    });

    it('la comisión del servicio manda sobre la de su categoría', function () {
        ($this->regla)(['type' => 'category', 'category_id' => $this->categoria, 'percent' => 35])->assertCreated();
        ($this->regla)(['type' => 'service', 'service_id' => $this->servicio->id, 'percent' => 40])->assertCreated();

        $fila = ($this->comisionDe)();

        expect(money($fila->amount))->toBe(40.0)
            ->and($fila->rule_name)->toBe('Comisión del servicio');
    });

    it('la excepción de la persona gana a la del servicio y a la de la categoría', function () {
        ($this->regla)(['type' => 'category', 'category_id' => $this->categoria, 'percent' => 35])->assertCreated();
        ($this->regla)(['type' => 'service', 'service_id' => $this->servicio->id, 'percent' => 40])->assertCreated();
        ($this->regla)(['type' => 'exception', 'service_id' => $this->servicio->id, 'professional_id' => $this->ana->id, 'percent' => 50])->assertCreated();

        $fila = ($this->comisionDe)();

        expect(money($fila->amount))->toBe(50.0)
            ->and($fila->rule_name)->toBe('Excepción');
    });

    it('apagada, vuelve a valer el porcentaje de la persona', function () {
        $id = ($this->regla)(['type' => 'category', 'category_id' => $this->categoria, 'percent' => 35])->assertCreated()->json('id');
        $this->as($this->co->owner)->putJson("/api/commission-rules/{$id}", ['type' => 'category', 'category_id' => $this->categoria, 'percent' => 35, 'active' => false])->assertOk();

        $fila = ($this->comisionDe)();

        expect(money($fila->amount))->toBe(30.0)
            ->and($fila->rule_name)->toBe('Su porcentaje');
    });

    it('un servicio de otra categoría no la toma', function () {
        ($this->regla)(['type' => 'category', 'category_id' => categoriaDeComision($this->co->tenant, 'Cortes'), 'percent' => 35])->assertCreated();

        expect(money(($this->comisionDe)()->amount))->toBe(30.0);
    });

    it('«¿Cuánto gana?» explica que viene de la categoría', function () {
        ($this->regla)(['type' => 'category', 'category_id' => $this->categoria, 'percent' => 35])->assertCreated();

        $this->as($this->co->owner)->getJson('/api/commission-rules/preview?' . http_build_query(['professional_id' => $this->ana->id, 'kind' => 'service', 'reference_id' => $this->servicio->id]))
            ->assertOk()
            ->assertJsonPath('source', 'category')
            ->assertJsonPath('label', 'Comisión de la categoría')
            ->assertJsonPath('amount', 35);

        expect($this->as($this->co->owner)->getJson('/api/commission-rules/preview?' . http_build_query(['professional_id' => $this->ana->id, 'kind' => 'service', 'reference_id' => $this->servicio->id]))->json('message'))
            ->toContain('categoría Color');
    });
});

describe('reglas por categoría', function () {
    it('no deja dos comisiones para la misma categoría', function () {
        ($this->regla)(['type' => 'category', 'category_id' => $this->categoria, 'percent' => 35])->assertCreated();

        ($this->regla)(['type' => 'category', 'category_id' => $this->categoria, 'percent' => 10])
            ->assertStatus(422)
            ->assertJsonPath('code', 'COMMISSION_RULE_EXISTS');

        expect(fn () => DB::table('commission_rules')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'name' => 'Repetida', 'kind' => 'service',
            'category_id' => $this->categoria, 'percent' => 5, 'fixed' => 0, 'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]))->toThrow(\Illuminate\Database\QueryException::class);
    });

    it('pide la categoría', function () {
        ($this->regla)(['type' => 'category', 'percent' => 35])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');
    });

    it('aparece en la lista con sus categorías', function () {
        ($this->regla)(['type' => 'category', 'category_id' => $this->categoria, 'percent' => 35])->assertCreated();

        $datos = $this->as($this->co->owner)->getJson('/api/commission-rules')->assertOk()->json();

        expect(collect($datos['rules'])->firstWhere('type', 'category')['category'])->toBe('Color')
            ->and(collect($datos['categories'])->pluck('id'))->toContain($this->categoria);
    });

    it('sin permiso para reglas no se puede crear', function () {
        $this->as($this->ana->user)->postJson('/api/commission-rules', ['type' => 'category', 'category_id' => $this->categoria, 'percent' => 99])->assertForbidden();

        expect(DB::table('commission_rules')->whereNotNull('category_id')->count())->toBe(0);
    });

    it('no acepta una categoría de otra empresa ni deja que otra empresa la cambie', function () {
        $otra = $this->company();
        $suya = categoriaDeComision($otra->tenant, 'Ajena');

        ($this->regla)(['type' => 'category', 'category_id' => $suya, 'percent' => 10])
            ->assertStatus(422)
            ->assertJsonValidationErrors('category_id');

        $id = ($this->regla)(['type' => 'category', 'category_id' => $this->categoria, 'percent' => 35])->assertCreated()->json('id');

        $this->as($otra->owner)->putJson("/api/commission-rules/{$id}", ['type' => 'category', 'category_id' => $suya, 'percent' => 1])->assertNotFound();
        $this->as($otra->owner)->deleteJson("/api/commission-rules/{$id}")->assertNotFound();
        expect(collect($this->as($otra->owner)->getJson('/api/commission-rules')->json('rules'))->pluck('id'))->not->toContain($id);
        expect(money(DB::table('commission_rules')->where('id', $id)->value('percent')))->toBe(35.0);
    });
});
