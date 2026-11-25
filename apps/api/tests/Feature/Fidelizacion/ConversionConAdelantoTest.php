<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->co = $this->company();
    $this->slug = DB::table('tenants')->where('id', $this->co->tenant->id)->value('slug');
    $this->servicio = $this->servicePriced($this->co->tenant, 80, 'Manicure');
    DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 60, 'online_booking' => true, 'requires_deposit' => true, 'deposit_type' => 'percent', 'deposit_value' => 30]);
    $this->ana = $this->professional($this->co, 'Ana Torres');
    RateLimiter::clear('write|' . $this->slug . '|127.0.0.1');

    $this->medir = fn () => $this->as($this->co->owner)->putJson('/api/link-page/channels', ['google_enabled' => false, 'ga4_id' => 'G-AB12CD34EF', 'meta_pixel_id' => '123456789012345'])->assertOk();

    $this->reservar = fn (string $hora, array $extra = []) => $this->postJson("/api/public/booking/{$this->slug}/appointments", $extra + [
        'service_id'      => $this->servicio->id,
        'branch_id'       => $this->co->branch->id,
        'professional_id' => $this->ana->id,
        'starts_at'       => $this->at($this->co->tenant, $hora),
        'first_name'      => 'Rosa',
        'last_name'       => 'Huamán',
        'document_number' => '4567' . random_int(1000, 9999),
        'phone'           => '98765' . random_int(1000, 9999),
        'email'           => Str::lower(Str::random(8)) . '@cliente.test',
        'accept_privacy'  => true,
        'request_key'     => (string) Str::uuid(),
    ]);
});

it('tras pagar el adelanto el link trae la conversión con el valor del servicio y se marca una sola vez', function () {
    ($this->medir)();
    $token = ($this->reservar)('10:00', ['channel' => 'instagram'])->assertStatus(202)->json('deposit.token');

    $pendiente = $this->getJson("/api/pay/{$token}")->assertOk()->json('conversion');
    expect($pendiente['tracking']['ga4_id'])->toBe('G-AB12CD34EF')
        ->and($pendiente['sent'])->toBeFalse();

    $this->postJson("/api/pay/{$token}/conversion")->assertOk()->assertJsonPath('send', false);

    $this->postJson("/api/pay/{$token}/simulate", ['result' => 'approved'])->assertOk();
    $pagado = $this->getJson("/api/pay/{$token}")->assertOk()->json();
    $codigo = DB::table('appointments')->where('tenant_id', $this->co->tenant->id)->value('code');

    expect($pagado['status'])->toBe('paid')
        ->and($pagado['conversion']['transaction_id'])->toBe($codigo)
        ->and((float) $pagado['conversion']['value'])->toBe(80.0)
        ->and((float) $pagado['conversion']['deposit'])->toBe(24.0)
        ->and($pagado['conversion']['currency'])->toBe($pagado['currency'])
        ->and($pagado['conversion']['service'])->toBe('Manicure');

    $this->postJson("/api/pay/{$token}/conversion")->assertOk()->assertJsonPath('send', true);
    $this->postJson("/api/pay/{$token}/conversion")->assertOk()->assertJsonPath('send', false);
    $this->getJson("/api/pay/{$token}")->assertJsonPath('conversion.sent', true);
});

it('sin códigos de medición no hay conversión', function () {
    $token = ($this->reservar)('10:00', ['channel' => 'instagram'])->assertStatus(202)->json('deposit.token');
    $this->postJson("/api/pay/{$token}/simulate", ['result' => 'approved'])->assertOk();

    $this->getJson("/api/pay/{$token}")->assertOk()->assertJsonPath('conversion', null);
});

it('un link de pago que no viene de la reserva en línea no manda la conversión', function () {
    ($this->medir)();
    $token = ($this->reservar)('10:00', ['channel' => 'instagram'])->assertStatus(202)->json('deposit.token');
    DB::table('payment_links')->where('token', $token)->update(['channel' => null]);
    $this->postJson("/api/pay/{$token}/simulate", ['result' => 'approved'])->assertOk();

    $this->getJson("/api/pay/{$token}")->assertOk()->assertJsonPath('conversion', null);
    $this->postJson("/api/pay/{$token}/conversion")->assertOk()->assertJsonPath('send', false);
});

it('la reserva desde el portal con adelanto también lleva la conversión', function () {
    ($this->medir)();
    $clienta = $this->customer($this->co->tenant, 'Lucía', 'Portal');

    $token = $this->as($clienta)->postJson('/api/portal/appointments', [
        'service_id' => $this->servicio->id, 'branch_id' => $this->co->branch->id, 'professional_id' => $this->ana->id,
        'starts_at'  => $this->at($this->co->tenant, '11:00'),
    ])->assertStatus(202)->json('deposit.token');
    $this->postJson("/api/pay/{$token}/simulate", ['result' => 'approved'])->assertOk();

    expect($this->getJson("/api/pay/{$token}")->json('conversion.sent'))->toBeFalse();
});
