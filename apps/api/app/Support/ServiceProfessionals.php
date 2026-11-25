<?php

namespace App\Support;

use App\Models\Professional;
use App\Models\Service;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ServiceProfessionals
{
    public static function idsOf(string $serviceId): array
    {
        return DB::table('service_professionals')->where('service_id', $serviceId)->pluck('professional_id')->all();
    }

    public static function map(string $tenantId): Collection
    {
        return DB::table('service_professionals')
            ->where('tenant_id', $tenantId)
            ->get(['service_id', 'professional_id'])
            ->groupBy('service_id')
            ->map(fn (Collection $rows) => $rows->pluck('professional_id')->values()->all());
    }

    public static function save(Service $service, ?array $professionalIds, ?array $visible = null): void
    {
        if ($professionalIds === null) {
            return;
        }

        if ($visible !== null) {
            $professionalIds = array_merge(array_intersect($professionalIds, $visible), array_diff(self::idsOf($service->id), $visible));
        }

        $valid = Professional::where('tenant_id', $service->tenant_id)
            ->whereIn('id', array_values(array_unique($professionalIds)))
            ->pluck('id')
            ->all();

        DB::table('service_professionals')->where('service_id', $service->id)->delete();
        DB::table('service_professionals')->insert(array_map(fn (string $id) => [
            'tenant_id'       => $service->tenant_id,
            'service_id'      => $service->id,
            'professional_id' => $id,
        ], $valid));
    }

    public static function doneBy(Professional $professional): array
    {
        $map = self::map($professional->tenant_id);

        return Service::where('tenant_id', $professional->tenant_id)->pluck('id')
            ->filter(fn (string $id) => !isset($map[$id]) || in_array($professional->id, $map[$id], true))
            ->values()->all();
    }

    public static function lastOnes(Professional $professional, array $serviceIds): array
    {
        $map = self::map($professional->tenant_id);
        $wanted = array_flip($serviceIds);
        $alone = \App\Support\CatalogExtras::activeProfessionals($professional->tenant_id)->pluck('id')->diff([$professional->id])->isEmpty();

        return Service::where('tenant_id', $professional->tenant_id)->orderBy('name')->get(['id', 'name'])
            ->filter(fn (Service $s) => !isset($wanted[$s->id]) && (isset($map[$s->id]) ? $map[$s->id] === [$professional->id] : $alone))
            ->pluck('name')->values()->all();
    }

    public static function syncFor(Professional $professional, array $serviceIds): void
    {
        $map = self::map($professional->tenant_id);
        $wanted = array_flip($serviceIds);
        $everyone = null;

        foreach (Service::where('tenant_id', $professional->tenant_id)->get() as $service) {
            $current = $map[$service->id] ?? [];
            $does = $current === [] || in_array($professional->id, $current, true);
            $want = isset($wanted[$service->id]);

            if ($does === $want) {
                continue;
            }

            if ($want) {
                $next = [...$current, $professional->id];
            } elseif ($current === []) {
                $everyone ??= \App\Support\CatalogExtras::activeProfessionals($professional->tenant_id)->pluck('id')->all();
                $next = array_values(array_diff($everyone, [$professional->id]));
            } else {
                $next = array_values(array_diff($current, [$professional->id]));
            }

            if ($next !== []) {
                self::save($service, $next);
            }
        }
    }

    public static function visibleTo(mixed $user): ?array
    {
        $limit = Access::branchLimit($user);

        if (!$limit) {
            return null;
        }

        return Professional::where('tenant_id', $user->tenant_id)
            ->where(fn ($q) => $q->whereIn('id', Access::professionalsAt($limit))
                ->orWhereNotIn('id', DB::table('professional_schedules')->select('professional_id')))
            ->pluck('id')
            ->all();
    }

    public static function names(string $serviceId): ?string
    {
        $ids = self::idsOf($serviceId);

        return $ids ? Professional::whereIn('id', $ids)->orderBy('display_name')->pluck('display_name')->implode(', ') : null;
    }
}
