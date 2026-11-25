<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\Tenant;
use Illuminate\Support\Carbon;

class PublicHours
{
    public const DAY_NAMES = ['el domingo', 'el lunes', 'el martes', 'el miércoles', 'el jueves', 'el viernes', 'el sábado'];

    public static function initials(string $name): string
    {
        $words = collect(preg_split('/\s+/u', trim($name)) ?: [])
            ->map(fn ($w) => preg_replace('/[^\p{L}\p{N}]/u', '', $w))
            ->filter(fn ($w) => $w !== '' && !in_array(mb_strtolower($w), ['de', 'del', 'la', 'el', 'y', 'los', 'las'], true))
            ->values();

        return mb_strtoupper($words->take(2)->map(fn ($w) => mb_substr($w, 0, 1))->implode('')) ?: '?';
    }

    public static function branches(Tenant $tenant): array
    {
        return Branch::where('tenant_id', $tenant->id)->where('status', 'active')->orderBy('name')
            ->get(['id', 'name', 'address', 'district', 'department', 'phone', 'timezone'])
            ->map(function (Branch $b) use ($tenant) {
                $address = collect([$b->address, $b->district, $b->department])->map(fn ($v) => trim((string) $v))->filter()->implode(', ') ?: null;

                return [
                    'id'      => $b->id,
                    'name'    => $b->name,
                    'address' => $address,
                    'phone'   => trim((string) $b->phone) ?: null,
                    'map_url' => $address ? 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($address) : null,
                    'hours'   => (object) BranchHours::of($b->id),
                    'state'   => self::state($tenant, $b),
                ];
            })->values()->all();
    }

    public static function state(Tenant $tenant, Branch $branch, ?Carbon $now = null): ?array
    {
        $hours = BranchHours::of($branch->id);

        if ($hours === []) {
            return null;
        }

        $zone = $branch->timezone ?: $tenant->zone();
        $now = ($now ?? Carbon::now())->copy()->setTimezone($zone);
        $time = $now->format('H:i');

        if (!self::closedOn($tenant, $branch, $now->toDateString())) {
            foreach ($hours[$now->dayOfWeek] ?? [] as $range) {
                if ($range['opens'] <= $time && $time < $range['closes']) {
                    return ['open' => true, 'text' => "Abierto ahora · cierra a las {$range['closes']}"];
                }
            }
        }

        for ($offset = 0; $offset < 14; $offset++) {
            $day = $now->copy()->addDays($offset);

            if (self::closedOn($tenant, $branch, $day->toDateString())) {
                continue;
            }

            foreach ($hours[$day->dayOfWeek] ?? [] as $range) {
                if ($offset > 0 || $time < $range['opens']) {
                    $when = $offset === 0 ? 'hoy' : ($offset === 1 ? 'mañana' : self::DAY_NAMES[$day->dayOfWeek]);

                    return ['open' => false, 'text' => "Cerrado · abre {$when} a las {$range['opens']}"];
                }
            }
        }

        return ['open' => false, 'text' => 'Cerrado por ahora'];
    }

    public static function closedOn(Tenant $tenant, Branch $branch, string $date): bool
    {
        return Holidays::on($tenant, $branch->id, $date, true)->isNotEmpty();
    }

    public static function opensOn(Tenant $tenant, Branch $branch, Carbon $date): bool
    {
        if (self::closedOn($tenant, $branch, $date->toDateString())) {
            return false;
        }

        $hours = BranchHours::of($branch->id);

        return $hours === [] || !empty($hours[$date->dayOfWeek]);
    }
}
