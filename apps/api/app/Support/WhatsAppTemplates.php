<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\User;
use App\Support\WhatsApp\CloudApiProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WhatsAppTemplates
{
    public const NOT_READY = 'Falta actualizar la base de datos para guardar las plantillas de WhatsApp: ejecuta php artisan migrate.';

    public const STATUS_LABELS = [
        'APPROVED' => 'Aprobada',
        'PENDING'  => 'En revisión',
        'REJECTED' => 'Rechazada',
        'PAUSED'   => 'Pausada por Meta',
        'DISABLED' => 'Desactivada por Meta',
        'DELETED'  => 'Ya no está en Meta',
        'UNKNOWN'  => 'Sin estado',
    ];

    public const CATEGORIES = [
        'UTILITY'   => 'Aviso de servicio (recordatorios, confirmaciones)',
        'MARKETING' => 'Promoción (campañas y ofertas)',
    ];

    public const STALE_MINUTES = 360;

    public const RETRY_MINUTES = 15;

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('whatsapp_templates');
    }

    public static function forget(): void
    {
        self::$ready = null;
    }

    public static function verifiable(?array $config): bool
    {
        if (!$config || WhatsApp::simulated($config)) {
            return false;
        }

        return $config['provider'] === '360dialog' || ($config['provider'] === 'cloud' && !empty($config['business_account_id']));
    }

    public static function normalizeStatus(?string $status): string
    {
        $status = strtoupper(trim((string) $status));

        return match (true) {
            $status === '' => 'UNKNOWN',
            in_array($status, ['APPROVED', 'PENDING', 'REJECTED', 'PAUSED', 'DISABLED', 'DELETED'], true) => $status,
            in_array($status, ['IN_APPEAL', 'PENDING_DELETION', 'SUBMITTED'], true) => 'PENDING',
            in_array($status, ['FLAGGED', 'LIMIT_EXCEEDED', 'ARCHIVED'], true) => 'PAUSED',
            default => 'UNKNOWN',
        };
    }

    public static function sync(Tenant $tenant, ?array $config = null): array
    {
        $config ??= WhatsApp::config($tenant->id);

        if (!$config) {
            return ['ok' => false, 'message' => 'Primero guarda los datos de WhatsApp.', 'count' => 0];
        }

        $provider = WhatsApp::provider($config);
        $result = $provider->templates($config);
        Cache::put(self::syncKey($tenant->id), now()->toIso8601String(), now()->addMinutes(self::RETRY_MINUTES));

        if (!$result['ok']) {
            IntegrationLog::write($tenant->id, 'whatsapp', $provider->key(), 'templates', false, $result['message']);

            return ['ok' => false, 'message' => $result['message'], 'count' => 0];
        }

        if (!self::ready() || WhatsApp::simulated($config)) {
            return ['ok' => true, 'message' => null, 'count' => count($result['templates']), 'templates' => $result['templates']];
        }

        if (empty($result['listed'])) {
            IntegrationLog::write($tenant->id, 'whatsapp', $provider->key(), 'templates', false, 'La respuesta no trajo la lista de plantillas: no se cambió nada.');

            return ['ok' => false, 'message' => 'WhatsApp respondió, pero sin la lista de plantillas. Vuelve a intentarlo en unos minutos.', 'count' => 0];
        }

        $now = now();
        $seen = [];

        foreach ($result['templates'] as $template) {
            $language = mb_substr($template['language'] ?: 'es', 0, 10);
            $name = mb_substr($template['name'], 0, 120);
            $seen[] = "{$name}|{$language}";
            $values = [
                'provider'    => $provider->key(),
                'status'      => self::normalizeStatus($template['status'] ?? null),
                'category'    => $template['category'] ? mb_substr((string) $template['category'], 0, 30) : null,
                'body'        => $template['body'] ?: null,
                'params'      => (int) ($template['params'] ?? 0),
                'reason'      => !empty($template['reason']) && strtoupper((string) $template['reason']) !== 'NONE' ? mb_substr((string) $template['reason'], 0, 500) : null,
                'external_id' => !empty($template['id']) ? mb_substr((string) $template['id'], 0, 80) : null,
                'synced_at'   => $now,
                'updated_at'  => $now,
            ];

            $current = DB::table('whatsapp_templates')->where('tenant_id', $tenant->id)->where('name', $name)->where('language', $language)->first();

            $current
                ? DB::table('whatsapp_templates')->where('id', $current->id)->update(array_filter($values, fn ($v) => $v !== null) + ['reason' => $values['reason']])
                : DB::table('whatsapp_templates')->insert($values + ['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => $name, 'language' => $language, 'created_at' => $now]);
        }

        DB::table('whatsapp_templates')->where('tenant_id', $tenant->id)->where('status', '!=', 'DELETED')
            ->get(['id', 'name', 'language', 'submitted_at'])
            ->reject(fn ($row) => in_array("{$row->name}|{$row->language}", $seen, true) || ($row->submitted_at && Carbon::parse($row->submitted_at)->gt($now->copy()->subMinutes(10))))
            ->each(fn ($row) => DB::table('whatsapp_templates')->where('id', $row->id)->update(['status' => 'DELETED', 'synced_at' => $now, 'updated_at' => $now]));

        IntegrationLog::write($tenant->id, 'whatsapp', $provider->key(), 'templates', true, count($result['templates']) . ' plantillas leídas y guardadas.');

        return ['ok' => true, 'message' => null, 'count' => count($result['templates'])];
    }

    private static function syncKey(string $tenantId): string
    {
        return "wa-templates-sync:{$tenantId}";
    }

    public static function lastSync(string $tenantId): ?Carbon
    {
        if (!self::ready()) {
            return null;
        }

        $value = DB::table('whatsapp_templates')->where('tenant_id', $tenantId)->max('synced_at');

        return $value ? Carbon::parse($value) : null;
    }

    public static function find(string $tenantId, string $name, string $language): ?object
    {
        if (!self::ready()) {
            return null;
        }

        return DB::table('whatsapp_templates')->where('tenant_id', $tenantId)->where('name', $name)->where('language', $language ?: 'es')->first();
    }

    public static function check(Tenant $tenant, array $config, string $name, string $language): ?string
    {
        if (!self::ready() || !self::verifiable($config)) {
            return null;
        }

        $row = self::find($tenant->id, $name, $language);
        $last = self::lastSync($tenant->id);
        $stale = !$last || $last->lt(now()->subMinutes(self::STALE_MINUTES)) || !$row || $row->status !== 'APPROVED';

        if ($stale && !Cache::has(self::syncKey($tenant->id))) {
            try {
                self::sync($tenant, $config);
            } catch (\Throwable $e) {
                report($e);
            }

            $row = self::find($tenant->id, $name, $language);
            $last = self::lastSync($tenant->id);
        }

        if (!$last) {
            return null;
        }

        if (!$row || $row->status === 'DELETED') {
            return "La plantilla «{$name}» ({$language}) no está en tu cuenta de WhatsApp: créala o envíala a revisión en Configuración → Mensajes → WhatsApp.";
        }

        if ($row->status !== 'APPROVED') {
            return "La plantilla «{$name}» ({$language}) no está aprobada por Meta (" . mb_strtolower(self::STATUS_LABELS[$row->status] ?? $row->status) . ').';
        }

        return null;
    }

    public static function statusUpdate(Tenant $tenant, array $value): bool
    {
        if (!self::ready()) {
            return false;
        }

        $name = (string) ($value['message_template_name'] ?? '');
        $language = (string) ($value['message_template_language'] ?? '');

        if ($name === '') {
            return false;
        }

        $query = DB::table('whatsapp_templates')->where('tenant_id', $tenant->id)->where('name', $name)->when($language !== '', fn ($q) => $q->where('language', $language));
        $status = self::normalizeStatus((string) ($value['event'] ?? ''));
        $reason = (string) ($value['reason'] ?? '');
        $values = [
            'status'     => $status,
            'reason'     => $reason !== '' && strtoupper($reason) !== 'NONE' ? mb_substr($reason, 0, 500) : null,
            'updated_at' => now(),
        ];

        if (!$query->exists()) {
            DB::table('whatsapp_templates')->insert($values + [
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'provider' => WhatsApp::config($tenant->id)['provider'] ?? 'cloud',
                'name' => mb_substr($name, 0, 120), 'language' => mb_substr($language ?: 'es', 0, 10),
                'external_id' => isset($value['message_template_id']) ? (string) $value['message_template_id'] : null, 'created_at' => now(),
            ]);
        } else {
            $query->update($values);
        }

        IntegrationLog::write($tenant->id, 'whatsapp', WhatsApp::config($tenant->id)['provider'] ?? 'cloud', 'templates', $status === 'APPROVED', "Meta avisó que la plantilla «{$name}» quedó: " . mb_strtolower(self::STATUS_LABELS[$status] ?? $status) . ($values['reason'] ? " ({$values['reason']})" : '') . '.');

        return true;
    }

    public static function placeholders(string $body): int
    {
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $matches);

        return $matches[1] ? max(array_map('intval', $matches[1])) : 0;
    }

    public static function submit(Tenant $tenant, array $data, ?User $user): object
    {
        abort_unless(self::ready(), 503, self::NOT_READY);
        $config = WhatsApp::config($tenant->id);

        if (!$config) {
            throw ValidationException::withMessages(['name' => 'Primero guarda los datos de WhatsApp.']);
        }

        $body = trim((string) $data['body']);
        $params = self::placeholders($body);
        preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $found);
        $numbers = array_values(array_unique(array_map('intval', $found[1])));
        sort($numbers);

        if ($numbers && $numbers !== range(1, $params)) {
            throw ValidationException::withMessages(['body' => 'Los datos van numerados en orden y sin saltos: {{1}}, {{2}}, {{3}}…']);
        }

        if ($params > 0 && preg_match('/^\s*\{\{\s*\d+\s*\}\}|\{\{\s*\d+\s*\}\}\s*$/', $body)) {
            throw ValidationException::withMessages(['body' => 'Meta no acepta que el texto empiece o termine con un dato ({{1}}): agrega una palabra antes o después.']);
        }

        $examples = array_values(array_map(fn ($v) => trim((string) $v), (array) ($data['examples'] ?? [])));

        if ($params > 0 && (count($examples) < $params || in_array('', array_slice($examples, 0, $params), true))) {
            throw ValidationException::withMessages(['examples' => 'Escribe un ejemplo para cada dato: Meta los pide para revisar la plantilla.']);
        }

        $name = $data['name'];
        $language = ($data['language'] ?? null) ?: 'es';

        if (($existing = self::find($tenant->id, $name, $language)) && $existing->status !== 'DELETED' && $existing->status !== 'REJECTED') {
            throw ValidationException::withMessages(['name' => 'Ya hay una plantilla con ese nombre e idioma (' . mb_strtolower(self::STATUS_LABELS[$existing->status] ?? $existing->status) . '). Usa otro nombre.']);
        }

        $payload = [
            'name'       => $name,
            'language'   => $language,
            'category'   => $data['category'],
            'components' => [array_filter([
                'type'    => 'BODY',
                'text'    => $body,
                'example' => $params > 0 ? ['body_text' => [array_slice($examples, 0, $params)]] : null,
            ])],
        ];

        $provider = WhatsApp::provider($config);
        $simulated = WhatsApp::simulated($config);
        $result = $simulated
            ? ['ok' => true, 'id' => 'sim-' . Str::lower(Str::random(10)), 'status' => 'APPROVED', 'message' => null]
            : $provider->createTemplate($config, $payload);

        IntegrationLog::write($tenant->id, 'whatsapp', $provider->key(), 'templates', $result['ok'], $result['ok'] ? "Plantilla «{$name}» enviada a revisión." : $result['message']);

        if (!$result['ok']) {
            throw ValidationException::withMessages(['name' => $result['message']]);
        }

        $values = [
            'provider'     => $provider->key(),
            'status'       => self::normalizeStatus($result['status'] ?? 'PENDING'),
            'category'     => $data['category'],
            'body'         => $body,
            'params'       => $params,
            'reason'       => null,
            'external_id'  => $result['id'] ? mb_substr((string) $result['id'], 0, 80) : null,
            'submitted_by' => $user?->id,
            'submitted_at' => now(),
            'updated_at'   => now(),
        ];

        $existing
            ? DB::table('whatsapp_templates')->where('id', $existing->id)->update($values)
            : DB::table('whatsapp_templates')->insert($values + ['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => $name, 'language' => $language, 'created_at' => now()]);

        return self::find($tenant->id, $name, $language);
    }

    public static function list(string $tenantId): array
    {
        if (!self::ready()) {
            return [];
        }

        return DB::table('whatsapp_templates')->where('tenant_id', $tenantId)->orderByRaw("case status when 'APPROVED' then 0 when 'PENDING' then 1 when 'REJECTED' then 2 else 3 end")->orderBy('name')->get()
            ->map(fn ($row) => self::present($row))->values()->all();
    }

    public static function approved(string $tenantId): array
    {
        return collect(self::list($tenantId))->where('status', 'APPROVED')->values()->all();
    }

    public static function present(object $row): array
    {
        return [
            'id'           => $row->id,
            'name'         => $row->name,
            'language'     => $row->language,
            'status'       => $row->status,
            'status_label' => self::STATUS_LABELS[$row->status] ?? $row->status,
            'category'     => $row->category,
            'body'         => (string) $row->body,
            'params'       => (int) $row->params,
            'reason'       => $row->reason,
            'submitted_at' => $row->submitted_at ? Carbon::parse($row->submitted_at)->toIso8601String() : null,
            'synced_at'    => $row->synced_at ? Carbon::parse($row->synced_at)->toIso8601String() : null,
        ];
    }

    public static function normalizeRows(array $rows): array
    {
        return CloudApiProvider::normalizeTemplates($rows);
    }
}
