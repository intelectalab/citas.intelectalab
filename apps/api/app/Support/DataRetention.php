<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\PlatformSetting;
use App\Models\Subscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DataRetention
{
    public const DEFAULTS = ['enabled' => false, 'days' => 90, 'warn_days' => [15, 7]];

    public static function ready(): bool
    {
        return Schema::hasColumn('tenants', 'data_purged_at') && PlatformLog::ready() && CustomerRecords::ready();
    }

    public static function config(): array
    {
        $stored = PlatformSetting::value('data_retention');
        $stored = is_array($stored) ? $stored : [];
        $days = max(30, min(3650, (int) ($stored['days'] ?? self::DEFAULTS['days'])));
        $warn = collect((array) ($stored['warn_days'] ?? self::DEFAULTS['warn_days']))->map(fn ($d) => (int) $d)
            ->filter(fn ($d) => $d >= 1 && $d < $days)->unique()->sortDesc()->values()->all();

        return [
            'enabled'   => (bool) ($stored['enabled'] ?? self::DEFAULTS['enabled']),
            'days'      => $days,
            'warn_days' => $warn ?: [7],
        ];
    }

    public static function save(array $values): array
    {
        PlatformSetting::put(['data_retention' => [
            'enabled'   => (bool) ($values['enabled'] ?? false),
            'days'      => (int) ($values['days'] ?? self::DEFAULTS['days']),
            'warn_days' => array_values(array_map('intval', (array) ($values['warn_days'] ?? self::DEFAULTS['warn_days']))),
        ]]);

        return self::config();
    }

    public static function candidates()
    {
        return Rls::bypass(fn () => Tenant::whereNull('data_purged_at')->get()->map(function (Tenant $tenant) {
            $subscription = Subscriptions::latest($tenant->id);

            return $subscription && $subscription->status === 'canceled' && $subscription->canceled_at ? [$tenant, $subscription] : null;
        })->filter()->values());
    }

    public static function purgeOn(Subscription $subscription): Carbon
    {
        return Carbon::parse($subscription->canceled_at)->addDays(self::config()['days']);
    }

    public static function run(): array
    {
        $counts = ['avisos' => 0, 'borradas' => 0];

        if (!self::ready() || !self::config()['enabled']) {
            return $counts;
        }

        $config = self::config();
        $lastWarn = min($config['warn_days']);

        foreach (self::candidates() as [$tenant, $subscription]) {
            try {
                $purgeOn = self::purgeOn($subscription);
                $left = (int) ceil(now()->diffInSeconds($purgeOn, false) / 86400);
                $due = collect($config['warn_days'])->filter(fn ($day) => $left <= $day && !PlatformLog::exists('tenant.purge_warning', $tenant->id, ['days' => $day, 'since' => self::since($subscription)]))->values();

                if ($due->isNotEmpty()) {
                    self::warn($tenant, $subscription, $due->all(), max(1, $left), $due->min() === $lastWarn);
                    $counts['avisos']++;

                    continue;
                }

                if ($left <= 0 && self::warnedLongEnough($tenant->id, $lastWarn, self::since($subscription))) {
                    self::purge($tenant);
                    $counts['borradas']++;
                }
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $counts;
    }

    public static function since(Subscription $subscription): string
    {
        return Carbon::parse($subscription->canceled_at)->utc()->format('Y-m-d H:i');
    }

    private static function warnedLongEnough(string $tenantId, int $days, string $since): bool
    {
        $first = Rls::bypass(fn () => DB::table('platform_audit_logs')->where('tenant_id', $tenantId)->where('action', 'tenant.purge_warning')
            ->whereRaw("after_data ->> 'days' = ?", [(string) $days])->whereRaw("after_data ->> 'since' = ?", [$since])->min('created_at'));

        return $first && Carbon::parse($first)->lte(now()->subDays($days)->addMinutes(5));
    }

    private static function owner(string $tenantId): ?User
    {
        return User::where('tenant_id', $tenantId)->where('is_owner', true)->first()
            ?? User::whereIn('id', Notifier::adminIds($tenantId))->first();
    }

    public static function warn(Tenant $tenant, Subscription $subscription, array $days, int $left, bool $prepareFile): void
    {
        $date = Carbon::now($tenant->zone())->addDays($left)->locale('es')->isoFormat('D [de] MMMM [de] YYYY');
        $text = "Tu empresa {$tenant->name} está dada de baja. El {$date} se borrarán los datos personales de tus clientes y de tu equipo (quedan solo los montos y comprobantes que la ley obliga a guardar)."
            . ' Si quieres conservarlos, descarga todos tus datos en Mi empresa → Plan o paga tu plan para volver.';
        $file = null;

        if ($prepareFile && DataExports::ready() && ($owner = self::owner($tenant->id))) {
            try {
                $file = DataExports::request($tenant, $owner);
                $text .= ' Ya te preparamos el archivo con todos tus datos.';
            } catch (\Throwable $e) {
                report($e);
            }
        }

        PlanBilling::notify($tenant->id, 'subscription.purge_warning', 'Se borrarán los datos de tu empresa', $text, "purge:{$tenant->id}:{$left}");
        $emails = User::where('tenant_id', $tenant->id)->where('status', 'active')
            ->where(fn ($q) => $q->where('is_owner', true)->orWhereIn('id', Notifier::adminIds($tenant->id)))->pluck('email')->unique();

        foreach ($emails as $email) {
            PlatformMail::trySend($email, 'Se borrarán los datos de tu empresa', '<p>' . e($text) . '</p>');
        }

        PlatformWhatsApp::notify($tenant->id, 'purge', [$tenant->name, $date]);

        foreach ($days as $day) {
            PlatformLog::system('tenant.purge_warning', $tenant->id, ['days' => $day, 'since' => self::since($subscription), 'date' => $date, 'file' => $file ? 'Preparado' : null], $tenant->name);
        }
    }

    public static function purge(Tenant $tenant): array
    {
        $done = Rls::bypass(fn () => DB::transaction(function () use ($tenant) {
            $customers = 0;

            Customer::withTrashed()->where('tenant_id', $tenant->id)->whereNull('anonymized_at')->orderBy('id')->chunkById(200, function ($rows) use (&$customers) {
                foreach ($rows as $customer) {
                    CustomerRecords::anonymize($customer);
                    $customers++;
                }
            });

            $users = 0;

            foreach (User::where('tenant_id', $tenant->id)->get() as $user) {
                $user->tokens()->delete();
                $user->forceFill([
                    'first_name'    => 'Usuario',
                    'last_name'     => 'borrado',
                    'email'         => 'borrado-' . $user->id . '@borrado.invalid',
                    'phone'         => null,
                    'status'        => 'inactive',
                    'password_hash' => Hash::make(Str::random(40)),
                ])->saveQuietly();
                $users++;
            }

            $files = 0;

            if (DataExports::ready()) {
                foreach (DB::table('data_exports')->where('tenant_id', $tenant->id)->whereNotNull('file_path')->get(['id', 'file_path']) as $row) {
                    Storage::disk('local')->delete($row->file_path);
                    DB::table('data_exports')->where('id', $row->id)->update(['file_path' => null, 'updated_at' => now()]);
                    $files++;
                }
            }

            DB::table('tenants')->where('id', $tenant->id)->update(['data_purged_at' => now(), 'updated_at' => now()]);

            return ['customers' => $customers, 'users' => $users, 'files' => $files];
        }));

        PlatformLog::system('tenant.data_purged', $tenant->id, ['clientes' => $done['customers'], 'usuarios' => $done['users'], 'archivos' => $done['files']], $tenant->name);

        return $done;
    }

    public static function present(): array
    {
        $config = self::config();

        return $config + [
            'ready'    => self::ready(),
            'upcoming' => self::ready() ? self::candidates()->map(fn ($pair) => [
                'tenant'   => ['id' => $pair[0]->id, 'name' => $pair[0]->name],
                'canceled' => Carbon::parse($pair[1]->canceled_at)->setTimezone($pair[0]->zone())->format('Y-m-d'),
                'purge_on' => self::purgeOn($pair[1])->setTimezone($pair[0]->zone())->format('Y-m-d'),
            ])->sortBy('purge_on')->values()->take(50)->all() : [],
        ];
    }
}
