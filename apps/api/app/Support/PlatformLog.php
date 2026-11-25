<?php

namespace App\Support;

use App\Models\PlatformAuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class PlatformLog
{
    public const PREFIX = 'PLT';

    public const DIGITS = 6;

    public const ACTIONS = [
        'platform.addon_created'       => ['Creó un complemento de planes', 'companies'],
        'platform.addon_updated'       => ['Cambió un complemento de planes', 'companies'],
        'platform.addon_deleted'       => ['Eliminó un complemento de planes', 'companies'],
        'platform.retention_changed'   => ['Cambió el borrado de datos de empresas dadas de baja', 'platform'],
        'platform.whatsapp_changed'    => ['Cambió el WhatsApp de la plataforma', 'platform'],
        'subscription.addon_added'     => ['Contrató un complemento', 'companies'],
        'subscription.addon_removed'   => ['Quitó un complemento desde el próximo cobro', 'companies'],
        'subscription.addon_kept'      => ['Anuló el retiro de un complemento', 'companies'],
        'subscription.overdue_whatsapp' => ['Avisó por WhatsApp un plan vencido', 'companies'],
        'tenant.purge_warning'         => ['Avisó que se borrarán los datos de la empresa', 'companies'],
        'tenant.data_purged'           => ['Borró los datos personales de una empresa dada de baja', 'companies'],
        'tenant.deleted_after_term'    => ['Borró la empresa al cumplirse el plazo pedido desde la consola', 'companies'],
    ];

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('platform_audit_logs');
    }

    public static function format(int $number): string
    {
        return self::PREFIX . '-' . str_pad((string) $number, self::DIGITS, '0', STR_PAD_LEFT);
    }

    public static function normalizeCode(string $code): string
    {
        $code = strtoupper(trim($code));

        return ctype_digit($code) ? self::format((int) $code) : (preg_match('/^PLT-?(\d+)$/', $code, $match) ? self::format((int) $match[1]) : $code);
    }

    public static function belongs(array $data): bool
    {
        return ($data['actor_type'] ?? null) === 'platform' || !empty($data['impersonator_user_id']) || !empty($data['platform']);
    }

    public static function record(array $data): ?string
    {
        if (!self::ready()) {
            return null;
        }

        try {
            return Rls::bypass(function () use ($data) {
                $number = (int) DB::selectOne("select nextval('platform_audit_logs_number_seq') as n")->n;
                $code = self::format($number);
                $json = fn ($value) => $value === null ? null : json_encode($value, JSON_UNESCAPED_UNICODE);

                DB::table('platform_audit_logs')->insert([
                    'id'                   => (string) Str::uuid(),
                    'number'               => $number,
                    'code'                 => $code,
                    'tenant_id'            => $data['tenant_id'] ?? null,
                    'actor_user_id'        => $data['actor_user_id'] ?? null,
                    'actor_type'           => $data['actor_type'] ?? 'system',
                    'actor_name'           => isset($data['actor_name']) ? mb_substr((string) $data['actor_name'], 0, 150) : null,
                    'impersonator_user_id' => $data['impersonator_user_id'] ?? null,
                    'action'               => mb_substr((string) $data['action'], 0, 80),
                    'entity_type'          => $data['entity_type'] ?? null,
                    'entity_id'            => isset($data['entity_id']) && Str::isUuid((string) $data['entity_id']) ? $data['entity_id'] : null,
                    'entity_label'         => isset($data['entity_label']) ? mb_substr((string) $data['entity_label'], 0, 200) : null,
                    'ip_address'           => isset($data['ip_address']) ? mb_substr((string) $data['ip_address'], 0, 45) : null,
                    'user_agent'           => isset($data['user_agent']) ? mb_substr((string) $data['user_agent'], 0, 500) : null,
                    'before_data'          => $json($data['before_data'] ?? null),
                    'after_data'           => $json($data['after_data'] ?? null),
                    'audit_event_id'       => $data['audit_event_id'] ?? null,
                    'created_at'           => now(),
                ]);

                return $code;
            });
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public static function system(string $action, ?string $tenantId, array $after = [], ?string $label = null, ?User $actor = null): ?string
    {
        return self::record([
            'tenant_id'     => $tenantId,
            'actor_user_id' => $actor?->id,
            'actor_type'    => $actor ? Audit::actorType($actor) : 'system',
            'actor_name'    => $actor ? Audit::nameOf($actor) : null,
            'action'        => $action,
            'entity_type'   => $tenantId ? 'tenant' : null,
            'entity_id'     => $tenantId,
            'entity_label'  => $label,
            'after_data'    => $after ?: null,
            'ip_address'    => app()->runningInConsole() ? null : request()->ip(),
            'user_agent'    => app()->runningInConsole() ? null : request()->userAgent(),
        ]);
    }

    public static function exists(string $action, string $tenantId, array $match = []): bool
    {
        if (!self::ready()) {
            return false;
        }

        return Rls::bypass(function () use ($action, $tenantId, $match) {
            $query = DB::table('platform_audit_logs')->where('action', $action)->where('tenant_id', $tenantId);

            foreach ($match as $key => $value) {
                $query->whereRaw('after_data ->> ? = ?', [$key, (string) $value]);
            }

            return $query->exists();
        });
    }

    public static function list(array $filters, int $limit): array
    {
        $from = Carbon::parse($filters['from'])->utc();
        $to = Carbon::parse($filters['to'])->utc();

        $rows = Rls::bypass(fn () => PlatformAuditLog::query()
            ->when($filters['code'] ?? null, fn ($q, $code) => $q->where('code', self::normalizeCode($code)), fn ($q) => $q->whereBetween('created_at', [$from, $to]))
            ->when($filters['tenant_id'] ?? null, fn ($q, $id) => $q->where('tenant_id', $id))
            ->orderByDesc('number')
            ->limit($limit + 1)
            ->get());

        $codes = $rows->pluck('code', 'id');
        $events = collect(Audit::present($rows->take($limit)))->map(fn ($row) => $row + ['code' => $codes[$row['id']] ?? null])->all();

        return ['events' => $events, 'truncated' => $rows->count() > $limit];
    }
}
