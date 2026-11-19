<?php

namespace App\Support;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Rls
{
    public const SHARED_CATALOGS = ['statuses', 'pipelines', 'units', 'roles'];

    private static ?string $tenant = null;

    private static bool $bypass = false;

    private static bool $touched = false;

    private static array $stack = [];

    public static function tenant(?string $tenantId): void
    {
        self::set($tenantId && Str::isUuid($tenantId) ? $tenantId : null, false);
    }

    public static function platform(): void
    {
        self::set(null, true);
    }

    public static function clear(): void
    {
        self::set(null, false);
    }

    public static function forUser(mixed $user): void
    {
        $tenantId = $user?->tenant_id ?? null;

        if ($user instanceof \App\Models\User && !$tenantId) {
            self::platform();

            return;
        }

        self::tenant($tenantId ? (string) $tenantId : null);
    }

    public static function within(?string $tenantId, bool $bypass, callable $callback): mixed
    {
        [$tenant, $wasBypass] = [self::$tenant, self::$bypass];
        self::set($tenantId, $bypass);

        try {
            return $callback();
        } finally {
            self::set($tenant, $wasBypass);
        }
    }

    public static function bypass(callable $callback): mixed
    {
        return self::within(self::$tenant, true, $callback);
    }

    public static function asTenant(?string $tenantId, callable $callback): mixed
    {
        return self::within($tenantId, false, $callback);
    }

    public static function commandStarted(): void
    {
        self::$stack[] = [self::$tenant, self::$bypass];
        self::platform();
    }

    public static function commandFinished(): void
    {
        [$tenant, $bypass] = array_pop(self::$stack) ?? [null, false];
        self::set($tenant, $bypass);
    }

    public static function current(): array
    {
        return ['tenant' => self::$tenant, 'bypass' => self::$bypass];
    }

    public static function apply(?Connection $connection = null): void
    {
        if (!self::$touched) {
            return;
        }

        $connection ??= self::openConnection();

        if (!$connection || $connection->getDriverName() !== 'pgsql') {
            return;
        }

        try {
            $connection->select(
                "select set_config('app.tenant_id', ?, false), set_config('app.bypass', ?, false)",
                [self::$tenant ?? '', self::$bypass ? 'on' : 'off'],
            );
        } catch (\Throwable) {
        }
    }

    public static function protect(string $table): void
    {
        DB::statement('select app_rls_protect(?, ?)', [$table, in_array($table, self::SHARED_CATALOGS, true)]);
    }

    public static function protectAll(): void
    {
        DB::statement('select app_rls_protect_all(?)', ['{' . implode(',', self::SHARED_CATALOGS) . '}']);
    }

    private static function set(?string $tenantId, bool $bypass): void
    {
        $changed = !self::$touched || self::$tenant !== $tenantId || self::$bypass !== $bypass;
        self::$tenant = $tenantId;
        self::$bypass = $bypass;
        self::$touched = true;

        if ($changed) {
            self::apply();
        }
    }

    private static function openConnection(): ?Connection
    {
        $manager = app('db');
        $name = $manager->getDefaultConnection();

        return $manager->getConnections()[$name] ?? null;
    }
}
