<?php

use App\Models\Subscription;
use App\Support\AccountSecurity;
use App\Support\Cancellations;
use App\Support\CustomerTransfers;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

const FOTO = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

function recibirComprobantes(string $tenantId, array $extra = []): void
{
    CustomerTransfers::save($tenantId, $extra + [
        'enabled'    => true,
        'accounts'   => [['bank' => 'BCP', 'holder' => 'Empresa SAC', 'account_number' => '191-1234567-0-11']],
        'yape_phone' => '987654321',
        'hold_hours' => 24,
    ]);
    Tests\TestCase::forgetCaches();
}

describe('mi cuenta', function () {
    it('cambia su contraseña con la actual, cierra las otras sesiones y quita los equipos de confianza', function () {
        $co = $this->company();
        $otro = $co->owner->createToken('otro equipo');
        AccountSecurity::trust(Request::create('/'), $co->owner);

        $this->as($co->owner)->putJson('/api/account/password', ['current_password' => 'mal', 'password' => 'nueva-clave-123', 'password_confirmation' => 'nueva-clave-123'])
            ->assertStatus(422)->assertJsonValidationErrors('current_password');

        $this->as($co->owner)->putJson('/api/account/password', ['current_password' => 'clave-de-prueba-123', 'password' => 'nueva-clave-123', 'password_confirmation' => 'nueva-clave-123'])
            ->assertOk();

        expect(Hash::check('nueva-clave-123', $co->owner->fresh()->password_hash))->toBeTrue()
            ->and(DB::table('personal_access_tokens')->where('id', $otro->accessToken->id)->exists())->toBeFalse()
            ->and(DB::table('account_tokens')->where('kind', 'trusted_device')->where('account_id', $co->owner->id)->exists())->toBeFalse();
    });

    it('lista sus equipos de confianza, marca el actual y quita solo los suyos', function () {
        $co = $this->company();
        $propio = AccountSecurity::trust(Request::create('/', 'GET', [], [], [], ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0']), $co->owner);
        AccountSecurity::trust(Request::create('/'), $co->owner);
        $ajeno = $this->staff($co, 'reception');
        AccountSecurity::trust(Request::create('/'), $ajeno);

        $lista = $this->as($co->owner)->getJson('/api/account/devices', ['X-Trusted-Device' => $propio])->assertOk()->json('devices');
        expect($lista)->toHaveCount(2)
            ->and(collect($lista)->firstWhere('current', true)['label'])->toBe('Chrome en Windows');

        $idAjeno = DB::table('account_tokens')->where('kind', 'trusted_device')->where('account_id', $ajeno->id)->value('id');
        $this->as($co->owner)->deleteJson("/api/account/devices/{$idAjeno}")->assertNotFound();
        $this->as($co->owner)->deleteJson("/api/account/devices/{$lista[1]['id']}")->assertOk();

        expect(DB::table('account_tokens')->where('kind', 'trusted_device')->where('account_id', $co->owner->id)->count())->toBe(1);
    });
});

describe('comisiones y meta', function () {
    it('muestra la meta con lo atendido en el mes, sesiones de paquete incluidas, y las reglas que le aplican', function () {
        $co = $this->company();
        $pro = $this->professional($co, 'Ana Torres', 20);
        $servicio = $this->servicePriced($co->tenant, 100, 'Corte');
        $clienta = $this->customer($co->tenant, 'Lucía', 'Paredes');
        $this->as($co->owner)->putJson("/api/professionals/{$pro->id}", ['goal_month' => 400])->assertOk();

        $citas = collect(['10:00', '11:00', '12:00'])->map(fn ($hora) => $this->book($co, $clienta, $servicio, $pro, $this->at($co->tenant, $hora))->assertCreated()->json('id'));
        $cambios = [['status' => 'completed'], ['status' => 'completed', 'source' => 'package'], ['status' => 'cancelled']];
        foreach ($cambios as $i => $cambio) {
            $inicio = now()->utc()->subMinutes(5 + 35 * $i);
            DB::table('appointments')->where('id', $citas[$i])->update($cambio + ['starts_at' => $inicio, 'ends_at' => $inicio->copy()->addMinutes(30)]);
        }

        $datos = $this->as($co->owner)->getJson("/api/professionals/{$pro->id}/commissions")->assertOk()->json();

        expect((float) $datos['goal'])->toBe(400.0)
            ->and((float) $datos['production'])->toBe(200.0)
            ->and($datos['progress'])->toBe(50)
            ->and((float) $datos['own_percent'])->toBe(20.0);
    });

    it('el profesional ve las suyas pero no las de un colega', function () {
        $co = $this->company();
        $ana = $this->professional($co, 'Ana Torres');
        $luis = $this->professional($co, 'Luis Rojas');

        $this->as($ana->user)->getJson("/api/professionals/{$ana->id}/commissions")->assertOk();
        $this->as($ana->user)->getJson("/api/professionals/{$luis->id}/commissions")->assertForbidden();
    });
});

describe('transferencia con comprobante', function () {
    beforeEach(function () {
        Storage::fake('local');
        $this->co = $this->company();
        $this->servicio = $this->servicePriced($this->co->tenant, 80, 'Manicure');
        DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 60, 'online_booking' => true, 'requires_deposit' => true, 'deposit_type' => 'percent', 'deposit_value' => 30]);
        $this->ana = $this->professional($this->co, 'Ana Torres');
        $this->clienta = $this->customer($this->co->tenant, 'Lucía', 'Paredes');
        recibirComprobantes($this->co->tenant->id);

        $this->reservar = fn (string $inicio, $quien = null) => $this->as($quien ?? $this->clienta)->postJson('/api/portal/appointments', [
            'service_id' => $this->servicio->id, 'branch_id' => $this->co->branch->id, 'professional_id' => $this->ana->id, 'starts_at' => $inicio,
        ]);
        $this->enviar = fn (string $token, string $metodo = 'yape') => $this->postJson("/api/pay/{$token}/voucher", [
            'method_id' => $metodo === 'yape' ? 'yape' : CustomerTransfers::config($this->co->tenant->id)['accounts'][0]['id'],
            'operation_number' => '12345678', 'paid_on' => now()->format('Y-m-d'), 'voucher' => FOTO,
        ]);
    });

    it('el cliente ve las cuentas y sube su comprobante; el horario queda apartado', function () {
        $inicio = $this->at($this->co->tenant, '10:00');
        $token = ($this->reservar)($inicio)->assertStatus(202)->json('deposit.token');

        $vista = $this->getJson("/api/pay/{$token}")->assertOk()->json();
        expect(collect($vista['transfer']['methods'])->pluck('method')->all())->toContain('transfer', 'yape');

        ($this->enviar)($token)->assertOk()->assertJsonPath('link.status', 'review');

        $otra = $this->customer($this->co->tenant, 'Marta', 'Quispe');
        $this->book($this->co, $otra, $this->servicio, $this->ana, $inicio)->assertStatus(409);
        expect(DB::table('payment_vouchers')->where('tenant_id', $this->co->tenant->id)->value('status'))->toBe('review');
    });

    it('la agenda muestra el horario apartado como reserva por confirmar', function () {
        $inicio = $this->at($this->co->tenant, '10:00');
        $dia = \Illuminate\Support\Carbon::parse($inicio)->format('Y-m-d');
        $token = ($this->reservar)($inicio)->assertStatus(202)->json('deposit.token');
        $buscar = fn ($bloques) => collect($bloques)->firstWhere('kind', 'held');

        expect($buscar(collect($this->as($this->co->owner)->getJson("/api/appointments/day?date={$dia}&branch_id={$this->co->branch->id}")->json('professionals'))->firstWhere('id', $this->ana->id)['blocks'] ?? []))->toBeNull();

        ($this->enviar)($token)->assertOk();

        $pro = collect($this->as($this->co->owner)->getJson("/api/appointments/day?date={$dia}&branch_id={$this->co->branch->id}")->assertOk()->json('professionals'))->firstWhere('id', $this->ana->id);
        $semana = $this->as($this->co->owner)->getJson("/api/appointments/range?from={$dia}&to={$dia}&branch_id={$this->co->branch->id}")->assertOk()->json('blocks');

        expect($buscar($pro['blocks']))->toMatchArray(['starts' => '10:00', 'ends' => '11:00', 'reason' => 'Lucía Paredes'])
            ->and($buscar($semana)['professional_id'])->toBe($this->ana->id);
    });

    it('el horario no queda apartado más allá de la hora de la cita', function () {
        $this->travelTo(now('America/Lima')->next('Monday')->setTime(8, 0));
        $token = ($this->reservar)($this->at($this->co->tenant, '10:00'))->assertStatus(202)->json('deposit.token');
        $pronto = now()->addHours(2)->startOfMinute()->utc();
        DB::table('payment_links')->where('token', $token)->update(['starts_at' => $pronto, 'ends_at' => $pronto->copy()->addHour()]);

        ($this->enviar)($token)->assertOk();

        expect(\Illuminate\Support\Carbon::parse(DB::table('payment_links')->where('token', $token)->value('held_until'))->equalTo($pronto))->toBeTrue();
    });

    it('al aprobar, la cita queda confirmada y el adelanto registrado', function () {
        $token = ($this->reservar)($this->at($this->co->tenant, '10:00'))->assertStatus(202)->json('deposit.token');
        ($this->enviar)($token)->assertOk();
        $voucher = DB::table('payment_vouchers')->where('tenant_id', $this->co->tenant->id)->first();

        expect($this->co->owner->fresh() ? \App\Support\CustomerTransfers::pendingCount($this->co->owner->fresh()) : 0)->toBe(1);

        $this->as($this->co->owner)->postJson("/api/payment-vouchers/{$voucher->id}/approve")->assertOk();

        $cita = DB::table('appointments')->where('customer_id', $this->clienta->id)->first();
        $pago = DB::table('payments')->where('appointment_id', $cita->id)->first();

        expect($cita->status)->toBe('confirmed')
            ->and(money($pago->amount))->toBe(24.0)
            ->and($pago->provider)->toBe('transfer')
            ->and($pago->external_reference)->toBe('12345678')
            ->and(DB::table('payment_vouchers')->where('id', $voucher->id)->value('status'))->toBe('approved');
    });

    it('al rechazar, el cliente ve el motivo y puede volver a pagar', function () {
        $token = ($this->reservar)($this->at($this->co->tenant, '10:00'))->assertStatus(202)->json('deposit.token');
        ($this->enviar)($token)->assertOk();
        $voucher = DB::table('payment_vouchers')->where('tenant_id', $this->co->tenant->id)->first();

        $this->as($this->co->owner)->postJson("/api/payment-vouchers/{$voucher->id}/reject", ['reason' => 'No encontramos el abono en la cuenta.'])->assertOk();

        $vista = $this->getJson("/api/pay/{$token}")->assertOk()->json();
        expect($vista['status'])->toBe('pending')
            ->and($vista['voucher']['status'])->toBe('rejected')
            ->and($vista['voucher']['reason'])->toBe('No encontramos el abono en la cuenta.')
            ->and(DB::table('appointments')->where('customer_id', $this->clienta->id)->exists())->toBeFalse();

        ($this->enviar)($token, 'transfer')->assertOk()->assertJsonPath('link.status', 'review');
    });

    it('no acepta dos comprobantes para el mismo pago ni de otra empresa', function () {
        $token = ($this->reservar)($this->at($this->co->tenant, '10:00'))->assertStatus(202)->json('deposit.token');
        ($this->enviar)($token)->assertOk();
        ($this->enviar)($token)->assertStatus(422);

        $voucher = DB::table('payment_vouchers')->where('tenant_id', $this->co->tenant->id)->first();
        $otra = $this->company();
        $this->as($otra->owner)->postJson("/api/payment-vouchers/{$voucher->id}/approve")->assertNotFound();
    });

    it('recepción no revisa comprobantes; caja sí', function () {
        $token = ($this->reservar)($this->at($this->co->tenant, '10:00'))->assertStatus(202)->json('deposit.token');
        ($this->enviar)($token)->assertOk();

        $this->as($this->staff($this->co, 'reception', $this->co->branch))->getJson('/api/payment-vouchers')->assertForbidden();
        $this->as($this->staff($this->co, 'cashier', $this->co->branch))->getJson('/api/payment-vouchers')->assertOk()->assertJsonCount(1, 'vouchers');
    });

    it('sin pasarela, la empresa igual crea links que se pagan con comprobante', function () {
        config(['payments.simulation' => false]);
        $this->openCash($this->co);
        $venta = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicio->id, 'quantity' => 1]]);

        $this->as($this->co->owner)->postJson("/api/sales/{$venta['id']}/links")->assertCreated();
        $link = DB::table('payment_links')->where('sale_id', $venta['id'])->first();

        expect($link->provider)->toBe('transfer');
        ($this->enviar)($link->token)->assertOk();
        $voucher = DB::table('payment_vouchers')->where('payment_link_id', $link->id)->first();
        $this->as($this->co->owner)->postJson("/api/payment-vouchers/{$voucher->id}/approve")->assertOk();

        expect($this->sale($venta['id'])->status)->toBe('paid');
    });
});

describe('dar de baja', function () {
    function suscripcionPagada(object $co): Subscription
    {
        $sub = Subscription::where('tenant_id', $co->tenant->id)->latest('starts_at')->first();
        $sub->update(['status' => 'active', 'billing_cycle' => 'monthly', 'ends_at' => now()->addDays(10), 'trial_ends_at' => null]);
        Tests\TestCase::forgetCaches();

        return $sub->fresh();
    }

    it('pide la baja: sigue todo hasta el fin del periodo, luego queda cancelada y en solo lectura', function () {
        $co = $this->company();
        $sub = suscripcionPagada($co);

        $this->as($co->owner)->postJson('/api/billing/cancel', ['reason' => 'price', 'confirm' => true])->assertOk()
            ->assertJsonPath('billing.cancellation.scheduled.reason', 'Me sale caro');

        expect(\App\Support\Subscriptions::lockedFor($co->tenant->id))->toBeFalse();

        $this->travel(11)->days();
        Cancellations::runDue();
        Tests\TestCase::forgetCaches();

        expect($sub->fresh()->status)->toBe('canceled')
            ->and(\App\Support\Subscriptions::lockedFor($co->tenant->id))->toBeTrue()
            ->and(DB::table('saas_cancellations')->where('subscription_id', $sub->id)->value('status'))->toBe('done');
    });

    it('se arrepiente con un clic antes de la fecha', function () {
        $co = $this->company();
        $sub = suscripcionPagada($co);
        $this->as($co->owner)->postJson('/api/billing/cancel', ['reason' => 'not_using', 'confirm' => true])->assertOk();

        $this->as($co->owner)->postJson('/api/billing/resume')->assertOk()->assertJsonPath('billing.cancellation.scheduled', null);

        $this->travel(11)->days();
        Cancellations::runDue();
        expect($sub->fresh()->status)->toBe('active')->and($sub->fresh()->cancel_at)->toBeNull();
    });

    it('solo el administrador la pide, y pide motivo y confirmación', function () {
        $co = $this->company();
        suscripcionPagada($co);

        $this->as($this->staff($co, 'reception', $co->branch))->postJson('/api/billing/cancel', ['reason' => 'price', 'confirm' => true])->assertForbidden();
        $this->as($co->owner)->postJson('/api/billing/cancel', ['confirm' => true])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->as($co->owner)->postJson('/api/billing/cancel', ['reason' => 'price'])->assertStatus(422)->assertJsonValidationErrors('confirm');
    });

    it('la oferta está apagada por defecto; encendida, quedarse aplica el descuento al cobro', function () {
        $co = $this->company();
        $sub = suscripcionPagada($co);
        expect($this->as($co->owner)->getJson('/api/billing')->json('billing.cancellation.offers'))->toBeEmpty();

        Cancellations::saveOffer(['enabled' => true, 'percent' => 50, 'months' => 2, 'reasons' => ['price']]);
        Tests\TestCase::forgetCaches();
        $precio = \App\Support\Subscriptions::price($sub->plan, 'monthly');

        $this->as($co->owner)->postJson('/api/billing/cancel', ['reason' => 'price', 'accept_offer' => true])->assertOk();
        $sub = $sub->fresh();

        expect((float) $sub->discount_percent)->toBe(50.0)
            ->and($sub->cancel_at)->toBeNull()
            ->and(Cancellations::chargeFor($sub, $sub->plan, 'monthly'))->toBe(round($precio / 2, 2));
    });

    it('descarga todos sus datos aunque esté en solo lectura', function () {
        $co = $this->company();
        $this->customer($co->tenant, 'Lucía', 'Paredes');
        Subscription::where('tenant_id', $co->tenant->id)->update(['status' => 'canceled', 'canceled_at' => now()]);
        Tests\TestCase::forgetCaches();

        $hojas = $this->as($co->owner)->getJson('/api/billing/export')->assertOk()->json('sheets');
        $clientes = collect($hojas)->firstWhere('sheet', 'Clientes');

        expect(collect($hojas)->pluck('sheet')->all())->toContain('Clientes', 'Citas', 'Ventas', 'Pagos', 'Servicios')
            ->and(collect($clientes['rows'])->pluck(1)->all())->toContain('Lucía');
    });
});

describe('constancia del plan', function () {
    it('el administrador descarga la constancia en PDF de un pago, y otra empresa no', function () {
        $co = $this->company();
        $sub = Subscription::where('tenant_id', $co->tenant->id)->latest('starts_at')->first();
        $invoice = \App\Support\Subscriptions::recordPayment($sub, ['amount' => 99, 'reason' => 'transfer', 'reference' => 'OP-1']);

        $respuesta = $this->as($co->owner)->get("/api/billing/invoices/{$invoice->id}/receipt")->assertOk();
        expect($respuesta->headers->get('Content-Type'))->toBe('application/pdf')
            ->and(str_starts_with($respuesta->getContent(), '%PDF'))->toBeTrue();

        $otra = $this->company();
        $this->as($otra->owner)->get("/api/billing/invoices/{$invoice->id}/receipt")->assertNotFound();
    });
});
