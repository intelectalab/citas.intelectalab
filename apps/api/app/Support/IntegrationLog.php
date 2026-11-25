<?php

namespace App\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class IntegrationLog
{
    public const NOT_READY = 'Falta actualizar la base de datos para guardar el registro de las integraciones: ejecuta php artisan migrate.';

    public const KINDS = [
        'payments'  => 'Pagos',
        'whatsapp'  => 'WhatsApp',
        'ai'        => 'Asistente',
        'invoicing' => 'Facturación',
        'email'     => 'Correo',
        'captcha'   => 'Verificación anti-robots',
    ];

    public const ACTIONS = [
        'test'      => 'Probar conexión',
        'charge'    => 'Cobro',
        'refund'    => 'Devolución',
        'webhook'   => 'Aviso recibido',
        'send'      => 'Envío',
        'templates' => 'Leer plantillas',
        'media'     => 'Archivo',
        'answer'    => 'Respuesta',
        'verify'    => 'Verificación',
    ];

    private static array $ready = [];

    public static function ready(?string $tenantId): bool
    {
        $table = $tenantId ? 'integration_logs' : 'platform_integration_logs';

        return self::$ready[$table] ??= Schema::hasTable($table);
    }

    public static function write(?string $tenantId, string $kind, string $provider, string $action, bool $ok, ?string $message = null): void
    {
        if (!self::ready($tenantId)) {
            return;
        }

        $row = [
            'id'         => (string) Str::uuid(),
            'kind'       => mb_substr($kind, 0, 30),
            'provider'   => mb_substr($provider, 0, 40),
            'action'     => mb_substr($action, 0, 40),
            'ok'         => $ok,
            'message'    => $message !== null ? mb_substr($message, 0, 500) : null,
            'created_at' => now(),
        ];

        try {
            $tenantId
                ? DB::table('integration_logs')->insert($row + ['tenant_id' => $tenantId])
                : DB::table('platform_integration_logs')->insert($row);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function recent(?string $tenantId, ?string $kind = null, int $limit = 40): array
    {
        if (!self::ready($tenantId)) {
            return [];
        }

        $query = $tenantId ? DB::table('integration_logs')->where('tenant_id', $tenantId) : DB::table('platform_integration_logs');

        if ($kind) {
            $query->where('kind', $kind);
        }

        return $query->orderByDesc('created_at')->limit($limit)->get()->map(fn ($row) => [
            'id'           => $row->id,
            'kind'         => $row->kind,
            'kind_label'   => self::KINDS[$row->kind] ?? $row->kind,
            'provider'     => $row->provider,
            'action'       => $row->action,
            'action_label' => self::ACTIONS[$row->action] ?? $row->action,
            'ok'           => (bool) $row->ok,
            'message'      => $row->message,
            'at'           => Carbon::parse($row->created_at)->toIso8601String(),
        ])->all();
    }
}
