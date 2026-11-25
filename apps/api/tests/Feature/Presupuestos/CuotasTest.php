<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company(['modules_off' => []]);
    $this->travelTo(Carbon::parse('2026-11-20 10:00', 'America/Lima'));
    $this->clienta = $this->customer($this->co->tenant, 'Paciente', 'Cuotas');
    $this->servicio = $this->servicePriced($this->co->tenant, 100, 'Ortodoncia');

    $this->crear = fn (array $cuotas) => $this->as($this->co->owner)->postJson('/api/quotes', [
        'customer_id' => $this->clienta->id, 'branch_id' => $this->co->branch->id, 'title' => 'Plan en cuotas',
        'items' => [['item_type' => 'service', 'reference_id' => $this->servicio->id, 'description' => 'Control', 'quantity' => 3, 'unit_price' => 100]],
        'installments' => $cuotas,
    ]);
    $this->cuotas = function (string $quoteId) {
        $q = collect($this->as($this->co->owner)->getJson('/api/quotes')->assertOk()->json('quotes'))->firstWhere('id', $quoteId);

        return collect($q['installments'])->sortBy('number')->pluck('status')->values()->all();
    };
});

it('cada pago de la venta marca las cuotas cubiertas y la que pasa su fecha sin pagar queda vencida', function () {
    ($this->crear)([
        ['due_date' => '2026-11-25', 'amount' => 150],
        ['due_date' => '2026-12-25', 'amount' => 150],
    ])->assertOk();
    $q = DB::table('quotes')->where('tenant_id', $this->co->tenant->id)->first();
    $this->as($this->co->owner)->postJson("/api/quotes/{$q->id}/status", ['status' => 'accepted'])->assertOk();
    $ventaId = DB::table('quotes')->where('id', $q->id)->value('sale_id');

    expect(($this->cuotas)($q->id))->toBe(['pending', 'pending']);

    $this->pay($this->co, $ventaId, ['method' => 'card', 'amount' => 150])->assertOk();
    expect(($this->cuotas)($q->id))->toBe(['paid', 'pending']);

    $this->travelTo(Carbon::parse('2026-12-27 10:00', 'America/Lima'));
    expect(($this->cuotas)($q->id))->toBe(['paid', 'overdue']);

    $this->pay($this->co, $ventaId, ['method' => 'card', 'amount' => 150])->assertOk();
    expect(($this->cuotas)($q->id))->toBe(['paid', 'paid'])
        ->and($this->sale($ventaId)->status)->toBe('paid');
});

it('un pago menor a la primera cuota no la marca pagada', function () {
    ($this->crear)([
        ['due_date' => '2026-11-25', 'amount' => 100],
        ['due_date' => '2026-12-25', 'amount' => 200],
    ])->assertOk();
    $q = DB::table('quotes')->where('tenant_id', $this->co->tenant->id)->first();
    $this->as($this->co->owner)->postJson("/api/quotes/{$q->id}/status", ['status' => 'accepted'])->assertOk();

    $this->pay($this->co, DB::table('quotes')->where('id', $q->id)->value('sale_id'), ['method' => 'card', 'amount' => 60])->assertOk();

    expect(($this->cuotas)($q->id))->toBe(['pending', 'pending']);
});

it('si la empresa no usa cuotas, no deja guardar un presupuesto con cuotas', function () {
    $this->setRules($this->co->tenant, ['quotes.allow_installments' => false]);

    ($this->crear)([
        ['due_date' => '2026-11-25', 'amount' => 150],
        ['due_date' => '2026-12-25', 'amount' => 150],
    ])->assertStatus(422)->assertJsonValidationErrors('installments');

    ($this->crear)([])->assertOk();
    expect(DB::table('quote_installments')->count())->toBe(0);
});

it('al anular el presupuesto aprobado las cuotas pendientes se cancelan', function () {
    ($this->crear)([
        ['due_date' => '2026-11-25', 'amount' => 150],
        ['due_date' => '2026-12-25', 'amount' => 150],
    ])->assertOk();
    $q = DB::table('quotes')->where('tenant_id', $this->co->tenant->id)->first();
    $this->as($this->co->owner)->postJson("/api/quotes/{$q->id}/status", ['status' => 'accepted'])->assertOk();

    $this->as($this->co->owner)->postJson("/api/quotes/{$q->id}/cancel", ['reason' => 'La paciente se mudó de ciudad'])->assertSuccessful();

    expect(DB::table('quote_installments')->where('quote_id', $q->id)->whereIn('status', ['pending', 'overdue'])->count())->toBe(0);
});
