<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

class GoogleReserve
{
    public const CHANNEL = 'google';

    public static function partner(): ?string
    {
        $value = trim((string) config('services.google_reserve.partner', ''));

        return $value !== '' ? $value : null;
    }

    public static function status(Tenant $tenant): array
    {
        $partner = self::partner();

        return [
            'available' => $partner !== null,
            'partner'   => $partner,
            'message'   => $partner
                ? 'La reserva completa dentro de Google se activa con el socio de Google. Pídelo a soporte.'
                : 'Por ahora Google muestra el botón «Reservar» que lleva a tu página de reservas. La reserva completa dentro de Google llegará más adelante.',
        ];
    }

    public static function merchant(Tenant $tenant): array
    {
        $branches = DB::table('branches')->where('tenant_id', $tenant->id)->where('status', 'active')->orderBy('name')->get(['id', 'name', 'address', 'district', 'department', 'phone', 'timezone']);

        return $branches->map(fn ($branch) => [
            'merchant_id' => $branch->id,
            'name'        => trim($tenant->name . ' · ' . $branch->name),
            'telephone'   => $branch->phone,
            'address'     => array_filter(['street' => $branch->address, 'locality' => $branch->district, 'region' => $branch->department, 'country' => 'PE']),
            'timezone'    => $branch->timezone,
            'booking_url' => PublicBooking::url($tenant) . '?origen=' . self::CHANNEL,
        ])->values()->all();
    }

    public static function services(Tenant $tenant): array
    {
        $currency = Currency::of($tenant);
        $branches = DB::table('branches')->where('tenant_id', $tenant->id)->where('status', 'active')->orderBy('name')->pluck('id')->all();

        return \App\Models\Service::where('tenant_id', $tenant->id)->where('online_booking', true)
            ->where('kind', '!=', 'group')->with('status')->orderBy('name')->get()
            ->reject(fn ($service) => $service->status?->slug === 'inactive')
            ->map(fn ($service) => [
                'service_id'       => $service->id,
                'name'             => $service->name,
                'duration_minutes' => (int) $service->duration_minutes,
                'price'            => ['amount' => round((float) $service->price, 2), 'currency' => $currency],
                'merchant_prices'  => collect($branches)->filter(fn ($id) => $service->offeredAt($id))
                    ->map(fn ($id) => ['merchant_id' => $id, 'amount' => round((float) $service->priceAt($id), 2), 'currency' => $currency])->values()->all(),
            ])->values()->all();
    }
    public static function feed(Tenant $tenant): array
    {
        return [
            'tenant'    => $tenant->slug,
            'active'    => LinkPage::google($tenant)['enabled'] && PublicBooking::open($tenant),
            'merchants' => self::merchant($tenant),
            'services'  => self::services($tenant),
        ];
    }
}
