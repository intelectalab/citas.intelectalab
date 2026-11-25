<?php

namespace App\Support;

use App\Models\AuditEvent;
use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class TenantDeletion
{
    public const NOT_READY = 'Falta actualizar la base de datos para borrar empresas con plazo: ejecuta php artisan migrate.';

    public static function ready(): bool
    {
        return Schema::hasColumn('tenants', 'delete_on');
    }

    public static function days(): int
    {
        return DataRetention::config()['days'];
    }

    public static function validate(Request $request, Tenant $tenant, bool $confirmName): string
    {
        $reason = trim((string) $request->input('reason'));

        if (mb_strlen($reason) < 10) {
            throw ValidationException::withMessages(['reason' => 'Escribe por qué (al menos 10 letras). Queda en la bitácora de la plataforma.']);
        }

        $plain = fn (string $value) => preg_replace('/\s+/', ' ', mb_strtolower(trim(\Illuminate\Support\Str::ascii($value))));

        if ($confirmName && $plain((string) $request->input('confirm_name')) !== $plain($tenant->name)) {
            throw ValidationException::withMessages(['confirm_name' => 'El nombre no coincide. Escribe el nombre de la empresa para confirmar (puede ser sin tildes).']);
        }

        return mb_substr($reason, 0, 500);
    }

    public static function schedule(Request $request, Tenant $tenant): Tenant
    {
        abort_unless(self::ready(), 503, self::NOT_READY);

        if ($tenant->deleted_at) {
            throw ValidationException::withMessages(['reason' => 'Esta empresa ya fue borrada.']);
        }

        if ($tenant->delete_on) {
            throw ValidationException::withMessages(['reason' => 'El borrado de esta empresa ya está programado para el ' . self::day($tenant, $tenant->delete_on) . '.']);
        }

        $reason = self::validate($request, $tenant, true);
        $deleteOn = now()->addDays(self::days());
        $previous = $tenant->status;
        $subscription = Subscriptions::latest($tenant->id);
        $previousSubscription = $subscription?->status;

        DB::transaction(function () use ($request, $tenant, $reason, $deleteOn, $subscription) {
            DB::table('tenants')->where('id', $tenant->id)->update([
                'status'                => 'inactive',
                'suspension_reason'     => mb_substr('Borrado programado: ' . $reason, 0, 255),
                'deletion_requested_at' => now(),
                'delete_on'             => $deleteOn,
                'deletion_reason'       => $reason,
                'deletion_requested_by' => $request->user()?->id,
                'updated_at'            => now(),
            ]);

            if ($subscription && $subscription->status !== 'canceled') {
                $subscription->update(['status' => 'canceled', 'canceled_at' => now()] + (Cancellations::ready() ? ['cancel_at' => null] : []));
            }

            self::closeSessions($tenant->id);
        });

        $tenant->refresh();
        Subscriptions::forget($tenant->id);
        SaasEvents::record($tenant->id, null, ['meta' => ['reason' => 'platform_delete']]);

        AuditEvent::record($request, 'tenant.deletion_scheduled', $tenant,
            ['status' => $previous, 'subscription' => $previousSubscription],
            ['status' => $tenant->status, 'subscription' => $subscription ? 'canceled' : null, 'delete_on' => self::day($tenant, $deleteOn), 'reason' => $reason]);

        return $tenant;
    }

    public static function cancel(Request $request, Tenant $tenant): Tenant
    {
        abort_unless(self::ready(), 503, self::NOT_READY);

        if (!$tenant->delete_on || $tenant->deleted_at) {
            throw ValidationException::withMessages(['reason' => 'Esta empresa no tiene un borrado programado.']);
        }

        $reason = self::validate($request, $tenant, false);
        $before = ['delete_on' => self::day($tenant, $tenant->delete_on), 'reason' => $tenant->deletion_reason];

        DB::table('tenants')->where('id', $tenant->id)->update([
            'deletion_requested_at' => null,
            'delete_on'             => null,
            'deletion_reason'       => null,
            'deletion_requested_by' => null,
            'suspension_reason'     => mb_substr('Borrado anulado: ' . $reason, 0, 255),
            'updated_at'            => now(),
        ]);

        $tenant->refresh();
        AuditEvent::record($request, 'tenant.deletion_canceled', $tenant, $before, ['reason' => $reason, 'status' => $tenant->status]);

        return $tenant;
    }

    public static function runDue(): int
    {
        if (!self::ready()) {
            return 0;
        }

        $done = 0;
        $due = Rls::bypass(fn () => Tenant::whereNotNull('delete_on')->whereNull('deleted_at')->where('delete_on', '<=', now())->get());

        foreach ($due as $tenant) {
            try {
                $purged = $tenant->data_purged_at || !DataRetention::ready() ? null : DataRetention::purge($tenant);
                Rls::bypass(fn () => DB::table('tenants')->where('id', $tenant->id)->update(['deleted_at' => now(), 'status' => 'inactive', 'updated_at' => now()]));
                PlatformLog::system('tenant.deleted_after_term', $tenant->id, ['motivo' => $tenant->deletion_reason, 'clientes' => $purged['customers'] ?? 0, 'usuarios' => $purged['users'] ?? 0], $tenant->name);
                $done++;
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $done;
    }

    public static function present(Tenant $tenant): ?array
    {
        if (!self::ready() || !$tenant->delete_on) {
            return null;
        }

        $on = Carbon::parse($tenant->delete_on);

        return [
            'requested_at' => self::day($tenant, $tenant->deletion_requested_at),
            'delete_on'    => self::day($tenant, $on),
            'days_left'    => max(0, (int) ceil(now()->diffInSeconds($on, false) / 86400)),
            'reason'       => $tenant->deletion_reason,
            'deleted'      => (bool) $tenant->deleted_at,
        ];
    }

    private static function day(Tenant $tenant, $value): ?string
    {
        return $value ? Carbon::parse($value)->setTimezone($tenant->zone())->format('Y-m-d') : null;
    }

    public static function closeSessions(string $tenantId): void
    {
        DB::table('personal_access_tokens')
            ->where(fn ($q) => $q
                ->whereIn('tokenable_id', DB::table('users')->where('tenant_id', $tenantId)->select(DB::raw('id::text')))
                ->orWhereIn('tokenable_id', DB::table('customers')->where('tenant_id', $tenantId)->select(DB::raw('id::text'))))
            ->delete();
    }
}
