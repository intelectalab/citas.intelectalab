<?php

use App\Support\Confirmations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->co = $this->company();
    $this->servicio = $this->servicePriced($this->co->tenant, 80, 'Masaje relajante');
    DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 60]);
    $this->ana = $this->professional($this->co, 'Ana Torres', 10);
    $this->clienta = $this->customer($this->co->tenant, 'Lucía', 'Paredes');

    $this->cita = $this->book($this->co, $this->clienta, $this->servicio, $this->ana, $this->at($this->co->tenant, '10:00'))->assertCreated()->json();
    $this->inicio = Carbon::parse(DB::table('appointments')->where('id', $this->cita['id'])->value('starts_at'));
    $this->pasar = fn (string $fase, array $extra = []) => $this->as($this->co->owner)
        ->postJson("/api/appointments/{$this->cita['id']}/status", ['status' => $fase] + $extra);
    $this->fila = fn () => DB::table('appointments')->where('id', $this->cita['id'])->first();
});

describe('las siete fases', function () {
    it('recorre pendiente, confirmada, llegó, en atención y atendida, guardando cada hora', function () {
        foreach (['confirmed', 'checked_in', 'in_progress', 'completed'] as $fase) {
            ($this->pasar)($fase)->assertOk()->assertJsonPath('status', $fase);
        }

        $fila = ($this->fila)();

        expect($fila->confirmed_at)->not->toBeNull()
            ->and($fila->checked_in_at)->not->toBeNull()
            ->and($fila->started_at)->not->toBeNull()
            ->and($fila->completed_at)->not->toBeNull()
            ->and(DB::table('appointment_status_history')->where('appointment_id', $this->cita['id'])->count())->toBe(5);
    });

    it('no salta pasos que la empresa exige', function () {
        ($this->pasar)('completed')->assertStatus(422)->assertJsonPath('code', 'INVALID_TRANSITION');
        ($this->pasar)('in_progress')->assertStatus(422);

        expect(($this->fila)()->status)->toBe('pending');
    });

    it('sin el paso de llegada, una cita confirmada puede darse por atendida', function () {
        $this->setRules($this->co->tenant, ['agenda.checkin_step' => false]);

        ($this->pasar)('confirmed')->assertOk();
        ($this->pasar)('completed')->assertOk();
    });

    it('una cita atendida ya no cambia de fase', function () {
        foreach (['confirmed', 'checked_in', 'completed'] as $fase) {
            ($this->pasar)($fase)->assertOk();
        }

        ($this->pasar)('cancelled', ['reason' => 'x'])->assertStatus(422);
        ($this->pasar)('pending')->assertStatus(422);
    });

    it('la empresa puede renombrar las fases y el mensaje usa su nombre', function () {
        DB::table('tenant_settings')->where('tenant_id', $this->co->tenant->id)->update(['appointment_statuses' => json_encode(['pending' => 'Por confirmar'])]);
        $this->forgetCaches();

        ($this->pasar)('completed')->assertStatus(422)->assertJsonFragment(['message' => 'No se puede pasar de «Por confirmar» a «Atendida».']);
    });
});

describe('cancelar', function () {
    it('pide el motivo', function () {
        ($this->pasar)('cancelled')->assertStatus(422)->assertJsonValidationErrors('reason');
        ($this->pasar)('cancelled', ['reason' => 'Se enfermó'])->assertOk();

        $fila = ($this->fila)();

        expect($fila->status)->toBe('cancelled')
            ->and($fila->cancel_reason)->toBe('Se enfermó')
            ->and($fila->cancelled_by)->toBe('staff');
    });

    it('con días de anticipación no es cancelación tardía', function () {
        ($this->pasar)('cancelled', ['reason' => 'Viaje'])->assertOk()->assertJsonPath('late_cancel', false);
    });

    it('a pocas horas de la cita sí es cancelación tardía', function () {
        $this->travelTo($this->inicio->copy()->subHours(2));

        ($this->pasar)('cancelled', ['reason' => 'Se le cruzó algo'])->assertOk()->assertJsonPath('late_cancel', true);
    });
});

describe('no asistió', function () {
    it('no se marca antes de la tolerancia', function () {
        ($this->pasar)('confirmed')->assertOk();
        $this->travelTo($this->inicio->copy()->addMinutes(5));

        ($this->pasar)('no_show')->assertStatus(422)->assertJsonPath('code', 'POLICY');
    });

    it('pasada la tolerancia sí se marca y queda en la auditoría', function () {
        ($this->pasar)('confirmed')->assertOk();
        $this->travelTo($this->inicio->copy()->addMinutes(20));

        ($this->pasar)('no_show')->assertOk();

        expect(($this->fila)()->status)->toBe('no_show')
            ->and(DB::table('audit_events')->where('entity_id', $this->cita['id'])->where('action', 'appointment.no_show')->exists())->toBeTrue();
    });
});

describe('plazo para confirmar', function () {
    it('si la empresa elige liberar, la cita sin confirmar se cancela sola al vencer el plazo', function () {
        $this->setRules($this->co->tenant, ['confirm.on_miss' => 'release', 'confirm.deadline_hours' => 24]);
        $this->travelTo($this->inicio->copy()->subHours(23));

        Confirmations::process($this->co->tenant->fresh());

        $fila = ($this->fila)();

        expect($fila->status)->toBe('cancelled')
            ->and($fila->cancelled_by)->toBe('system')
            ->and($fila->cancel_reason)->toBe(Confirmations::RELEASE_REASON);
    });

    it('antes del plazo no hace nada', function () {
        $this->setRules($this->co->tenant, ['confirm.on_miss' => 'release', 'confirm.deadline_hours' => 24]);
        $this->travelTo($this->inicio->copy()->subHours(30));

        Confirmations::process($this->co->tenant->fresh());

        expect(($this->fila)()->status)->toBe('pending');
    });

    it('por defecto solo avisa: la cita sigue pendiente', function () {
        $this->travelTo($this->inicio->copy()->subHours(23));

        Confirmations::process($this->co->tenant->fresh());

        expect(($this->fila)()->status)->toBe('pending')
            ->and(DB::table('user_notifications')->where('tenant_id', $this->co->tenant->id)->exists())->toBeTrue();
    });

    it('una cita confirmada no se libera', function () {
        $this->setRules($this->co->tenant, ['confirm.on_miss' => 'release']);
        ($this->pasar)('confirmed')->assertOk();
        $this->travelTo($this->inicio->copy()->subHours(2));

        Confirmations::process($this->co->tenant->fresh());

        expect(($this->fila)()->status)->toBe('confirmed');
    });
});

describe('cobrar la cita', function () {
    beforeEach(function () {
        $this->cobrar = fn (array $datos) => $this->as($this->co->owner)->postJson("/api/appointments/{$this->cita['id']}/charge", $datos);
    });

    it('cobra la cita con su precio, emite boleta y calcula la comisión de quien atiende', function () {
        ($this->cobrar)(['method' => 'card', 'amount' => 80, 'reference' => 'VOUCHER-9'])->assertOk();

        $venta = DB::table('sales')->where('appointment_id', $this->cita['id'])->first();

        expect($venta->status)->toBe('paid')
            ->and(money($venta->total))->toBe(80.0)
            ->and(DB::table('invoices')->where('sale_id', $venta->id)->value('doc_type'))->toBe('boleta')
            ->and(money(DB::table('commission_entries')->where('sale_id', $venta->id)->where('professional_id', $this->ana->id)->value('amount')))->toBe(6.78);
    });

    it('una cita cobrada no se cobra dos veces', function () {
        ($this->cobrar)(['method' => 'card', 'amount' => 80, 'reference' => 'A'])->assertOk();
        ($this->cobrar)(['method' => 'card', 'amount' => 80, 'reference' => 'B'])->assertStatus(422);

        expect(DB::table('payments')->where('appointment_id', $this->cita['id'])->count())->toBe(1);
    });

    it('en efectivo exige la caja abierta', function () {
        ($this->cobrar)(['method' => 'cash', 'amount' => 80])->assertStatus(409)->assertJsonPath('code', 'CASH_CLOSED');

        $this->openCash($this->co, 0);
        ($this->cobrar)(['method' => 'cash', 'amount' => 80])->assertOk();
    });

    it('si la empresa cobra antes de atender, no deja iniciar ni terminar sin pagar', function () {
        $this->setRules($this->co->tenant, ['pos.pay_before_service' => true]);
        ($this->pasar)('confirmed')->assertOk();
        ($this->pasar)('checked_in')->assertOk();

        ($this->pasar)('in_progress')->assertStatus(422)->assertJsonPath('code', 'PAYMENT_REQUIRED');
        ($this->pasar)('completed')->assertStatus(422)->assertJsonPath('code', 'PAYMENT_REQUIRED');

        ($this->cobrar)(['method' => 'yape', 'amount' => 80, 'reference' => 'Y-1'])->assertOk();

        ($this->pasar)('in_progress')->assertOk();
        ($this->pasar)('completed')->assertOk();
    });

    it('con un cupón, cobra el precio con descuento', function () {
        DB::table('coupons')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'code' => 'CITA25',
            'discount_type' => 'percent', 'discount_value' => 25, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        ($this->cobrar)(['method' => 'card', 'amount' => 60, 'reference' => 'C', 'coupon_code' => 'CITA25'])->assertOk();

        expect(money(DB::table('sales')->where('appointment_id', $this->cita['id'])->value('total')))->toBe(60.0);
    });
});
