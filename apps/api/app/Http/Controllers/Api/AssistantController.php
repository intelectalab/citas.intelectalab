<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Assistant;
use App\Support\Audit;
use App\Support\IntegrationLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AssistantController extends Controller
{
    private function tenant(Request $request, bool $edit): Tenant
    {
        abort_unless($request->user()->allows($edit ? 'settings.edit' : 'settings.view'), 403, $edit ? 'Tu cuenta no tiene permiso para cambiar la configuración de la empresa.' : 'Tu cuenta no tiene permiso para ver la configuración de la empresa.');
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 403);

        return $tenant->loadMissing(['settings', 'activeSubscription.plan']);
    }

    public static function providers(): array
    {
        return collect(Assistant::AI_PROVIDERS)->map(fn ($def, $key) => ['key' => $key, 'label' => $def['label'], 'default_model' => (string) config($def['model'])])->values()->all();
    }

    public static function summary(Tenant $tenant, bool $edit): array
    {
        $ready = self::kindReady();
        $own = $ready ? Assistant::tenantConfig($tenant->id) : null;
        $platform = Assistant::platformConfig();
        $mask = fn (?string $value) => $value ? str_repeat('•', 8) . mb_substr($value, -4) : null;

        return [
            'ai'        => [
                'ready'     => $ready,
                'own'       => $own ? [
                    'provider'  => $own['provider'],
                    'label'     => Assistant::AI_PROVIDERS[$own['provider']]['label'],
                    'model'     => $own['model'] ?? null,
                    'key_saved' => $mask($own['api_key']),
                    'saved_at'  => $own['updated_at'] ? Carbon::parse($own['updated_at'])->toIso8601String() : null,
                ] : null,
                'platform'  => $platform ? ['label' => Assistant::AI_PROVIDERS[$platform['provider']]['label']] : null,
                'providers' => self::providers(),
                'quota'     => Assistant::quota($tenant),
                'log'       => IntegrationLog::recent($tenant->id, 'ai', 20),
            ],
        ];
    }

    private static function kindReady(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('integration_logs');
    }

    public function save(Request $request)
    {
        $tenant = $this->tenant($request, true);
        abort_unless(self::kindReady(), 503, IntegrationLog::NOT_READY);

        $validated = $request->validate([
            'provider' => ['required', Rule::in(array_keys(Assistant::AI_PROVIDERS))],
            'api_key'  => 'nullable|string|max:400',
            'model'    => ['nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:-]+$/'],
        ], ['model.regex' => 'El nombre del modelo va sin espacios, como lo muestra el proveedor.']);

        $current = DB::table('tenant_integrations')->where('tenant_id', $tenant->id)->where('kind', 'ai')->first();
        $old = Assistant::decode($current);
        $key = trim((string) ($validated['api_key'] ?? '')) ?: (($old['provider'] ?? null) === $validated['provider'] ? ($old['api_key'] ?? null) : null);

        if (!$key) {
            throw ValidationException::withMessages(['api_key' => 'Pega la clave (API key) que te da ' . Assistant::AI_PROVIDERS[$validated['provider']]['label'] . '.']);
        }

        $config = ['provider' => $validated['provider'], 'api_key' => $key, 'model' => trim((string) ($validated['model'] ?? '')) ?: null];

        try {
            $check = Assistant::build($config)->ping();
        } catch (\RuntimeException $e) {
            $check = ['ok' => false, 'message' => $e->getMessage()];
        }

        IntegrationLog::write($tenant->id, 'ai', $validated['provider'], 'test', $check['ok'], $check['message']);

        if (!$check['ok']) {
            throw ValidationException::withMessages(['api_key' => $check['message']]);
        }

        $values = [
            'provider'    => $validated['provider'],
            'mode'        => 'production',
            'status'      => 'active',
            'credentials' => Crypt::encryptString(json_encode(['api_key' => $key])),
            'settings'    => json_encode(['model' => $config['model']]),
            'updated_at'  => now(),
        ];

        $current
            ? DB::table('tenant_integrations')->where('id', $current->id)->update($values)
            : DB::table('tenant_integrations')->insert($values + ['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'kind' => 'ai', 'created_at' => now()]);

        Audit::log($request, 'settings.changed', [
            'tenant' => $tenant->id, 'entity_type' => 'settings', 'entity_label' => 'Asistente con inteligencia artificial',
            'before' => ['assistant_provider' => $old ? Assistant::AI_PROVIDERS[$old['provider']]['label'] : 'Sin clave propia'],
            'after'  => ['assistant_provider' => Assistant::AI_PROVIDERS[$validated['provider']]['label']] + (trim((string) ($validated['api_key'] ?? '')) !== '' ? ['assistant_key' => 'Cambiada'] : []),
        ]);

        return response()->json(['message' => $check['message']] + self::summary($tenant, true));
    }

    public function remove(Request $request)
    {
        $tenant = $this->tenant($request, true);
        $deleted = Schema::hasTable('tenant_integrations') ? DB::table('tenant_integrations')->where('tenant_id', $tenant->id)->where('kind', 'ai')->delete() : 0;

        if ($deleted) {
            Audit::log($request, 'settings.changed', [
                'tenant' => $tenant->id, 'entity_type' => 'settings', 'entity_label' => 'Asistente con inteligencia artificial',
                'before' => ['assistant_provider' => 'Clave propia'], 'after' => ['assistant_provider' => 'Sin clave propia'],
            ]);
        }

        return response()->json(self::summary($tenant, true));
    }

    public function test(Request $request)
    {
        $tenant = $this->tenant($request, false);
        $config = Assistant::configFor($tenant);

        if (!$config) {
            return response()->json(['ok' => false, 'message' => 'Todavía no hay una clave de inteligencia artificial: pégala aquí o pide a la plataforma que active la suya.']);
        }

        try {
            $result = Assistant::build($config)->ping();
        } catch (\RuntimeException $e) {
            $result = ['ok' => false, 'message' => $e->getMessage()];
        }

        IntegrationLog::write($tenant->id, 'ai', $config['provider'], 'test', $result['ok'], $result['message']);

        return response()->json($result + self::summary($tenant, false));
    }
}
