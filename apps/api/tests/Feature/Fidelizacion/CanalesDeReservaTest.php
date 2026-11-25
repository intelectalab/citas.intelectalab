<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->co = $this->company();
    $this->slug = DB::table('tenants')->where('id', $this->co->tenant->id)->value('slug');
    $this->servicio = $this->servicePriced($this->co->tenant, 80, 'Manicure');
    DB::table('services')->where('id', $this->servicio->id)->update(['duration_minutes' => 60, 'online_booking' => true, 'requires_deposit' => false]);
    $this->ana = $this->professional($this->co, 'Ana Torres');
    RateLimiter::clear('write|' . $this->slug . '|127.0.0.1');

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

it('la cita guarda de qué canal vino y lo desconocido queda como directo', function () {
    $instagram = ($this->reservar)('09:00', ['channel' => 'instagram'])->assertCreated()->json('id');
    $google = ($this->reservar)('11:00', ['channel' => 'GOOGLE'])->assertCreated()->json('id');
    $raro = ($this->reservar)('13:00', ['channel' => 'cualquier-cosa'])->assertCreated()->json('id');
    $sinOrigen = ($this->reservar)('15:00')->assertCreated()->json('id');

    expect(DB::table('appointments')->where('id', $instagram)->value('channel'))->toBe('instagram')
        ->and(DB::table('appointments')->where('id', $google)->value('channel'))->toBe('google')
        ->and(DB::table('appointments')->where('id', $raro)->value('channel'))->toBe('direct')
        ->and(DB::table('appointments')->where('id', $sinOrigen)->value('channel'))->toBe('direct');

    $cita = $this->book($this->co, $this->customer($this->co->tenant), $this->servicio, $this->ana, $this->at($this->co->tenant, '17:00'))->assertCreated()->json('id');
    expect(DB::table('appointments')->where('id', $cita)->value('channel'))->toBeNull();
});

it('el portal marca sus reservas como del portal', function () {
    $clienta = $this->customer($this->co->tenant, 'Lucía', 'Portal');

    $id = $this->as($clienta)->postJson('/api/portal/appointments', [
        'service_id' => $this->servicio->id, 'branch_id' => $this->co->branch->id, 'professional_id' => $this->ana->id,
        'starts_at'  => $this->at($this->co->tenant, '10:00'),
    ])->assertCreated()->json('id');

    expect(DB::table('appointments')->where('id', $id)->value('channel'))->toBe('portal');
});

it('el reporte de citas cuenta las reservas por canal y cuadra con las citas', function () {
    ($this->reservar)('09:00', ['channel' => 'instagram'])->assertCreated();
    ($this->reservar)('10:00', ['channel' => 'instagram'])->assertCreated();
    ($this->reservar)('11:00', ['channel' => 'whatsapp'])->assertCreated();
    $this->travel(5)->days();

    $canales = collect($this->as($this->co->owner)->getJson('/api/reports?tab=appointments&period=30')->assertOk()->json('data.detail.by_channel'))->keyBy('key');

    expect($canales['instagram']['value'])->toBe(2)
        ->and($canales['instagram']['label'])->toBe('Instagram')
        ->and($canales['whatsapp']['value'])->toBe(1)
        ->and($canales->sum('value'))->toBe(DB::table('appointments')->where('tenant_id', $this->co->tenant->id)->where('source', 'online')->count());
});

it('la empresa arma su enlace para redes y se ve sin iniciar sesión con sus reseñas y su marca', function () {
    $this->getJson("/api/public/link/{$this->slug}")->assertNotFound();

    $this->as($this->co->owner)->putJson('/api/link-page', [
        'enabled' => true, 'title' => 'Spa Bella', 'bio' => 'Reserva en un minuto',
        'links' => [['label' => 'Nuestra carta', 'url' => 'ftp://mal']],
    ])->assertStatus(422)->assertJsonValidationErrors('links.0.url');

    $this->as($this->co->owner)->putJson('/api/link-page', [
        'enabled' => true, 'title' => 'Spa Bella', 'bio' => 'Reserva en un minuto',
        'links' => [['label' => 'Nuestra carta', 'url' => 'https://spa.test/carta']],
        'show_reviews' => true, 'show_whatsapp' => true, 'show_location' => true,
    ])->assertOk()->assertJsonPath('link_page.enabled', true);

    DB::table('branches')->where('id', $this->co->branch->id)->update(['address' => 'Av. Larco 123, Miraflores']);

    $pagina = $this->getJson("/api/public/link/{$this->slug}")->assertOk()->json();

    expect($pagina['title'])->toBe('Spa Bella')
        ->and($pagina['booking_url'])->toBe("/reservar/{$this->slug}?origen=link")
        ->and($pagina['links'][0]['label'])->toBe('Nuestra carta')
        ->and($pagina['branches'][0]['map_url'])->toContain('Larco')
        ->and($pagina)->toHaveKey('brand')
        ->and($pagina['reviews'])->toBeNull();

    $recepcion = $this->staff($this->co, 'reception');
    $this->as($recepcion)->putJson('/api/link-page', ['enabled' => false])->assertForbidden();
});

it('Google y la medición los activa la empresa con sus propios códigos', function () {
    $this->as($this->co->owner)->putJson('/api/link-page/channels', ['google_enabled' => true, 'ga4_id' => 'UA-123', 'meta_pixel_id' => 'abc'])
        ->assertStatus(422)->assertJsonValidationErrors(['ga4_id', 'meta_pixel_id']);

    $datos = $this->as($this->co->owner)->putJson('/api/link-page/channels', ['google_enabled' => true, 'ga4_id' => 'g-ab12cd34ef', 'meta_pixel_id' => '123456789012345'])
        ->assertOk()->json();

    expect($datos['google']['enabled'])->toBeTrue()
        ->and($datos['google']['booking_url'])->toEndWith("/reservar/{$this->slug}?origen=google")
        ->and($datos['tracking']['ga4_id'])->toBe('G-AB12CD34EF')
        ->and(collect($datos['channels'])->pluck('key'))->toContain('instagram', 'google', 'whatsapp');

    $this->getJson("/api/public/booking/{$this->slug}")->assertOk()
        ->assertJsonPath('tracking.ga4_id', 'G-AB12CD34EF')
        ->assertJsonPath('tracking.meta_pixel_id', '123456789012345');

    $feed = $this->as($this->co->owner)->getJson('/api/link-page/google-feed')->assertOk()->json();
    expect($feed['active'])->toBeTrue()
        ->and($feed['merchants'][0]['booking_url'])->toContain('origen=google')
        ->and(collect($feed['services'])->pluck('service_id'))->toContain($this->servicio->id);
});
