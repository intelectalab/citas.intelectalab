<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Access;
use App\Support\Audit;
use App\Support\Billing\NubefactInvoiceProvider;
use App\Support\Invoicing;
use App\Support\Sales;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InvoicingSettingsController extends Controller
{
    private function tenant(Request $request, string $permission): Tenant
    {
        $user = $request->user();
        abort_unless($user->tenant, 403);
        abort_unless($user->allows($permission), 403, $permission === 'settings.edit'
            ? 'Tu cuenta no tiene permiso para cambiar la configuración de la empresa.'
            : 'Tu cuenta no tiene permiso para ver la configuración de la empresa.');
        abort_unless(Sales::ready(), 503, Sales::NOT_READY);

        return $user->tenant->loadMissing(['settings', 'activeSubscription.plan']);
    }

    private static function credentials(?object $row): array
    {
        if (!$row || !$row->credentials) {
            return [];
        }

        try {
            return json_decode(Crypt::decryptString($row->credentials), true) ?: [];
        } catch (\Throwable $e) {
            return [];
        }
    }

    private static function maskRoute(?string $route): ?string
    {
        if (!$route) {
            return null;
        }

        $parts = parse_url($route);

        return ($parts['scheme'] ?? 'https') . '://' . ($parts['host'] ?? '') . '/…' . mb_substr($route, -4);
    }

    public function show(Request $request)
    {
        $tenant = $this->tenant($request, 'settings.view');

        return response()->json($this->payload($request, $tenant));
    }

    private function payload(Request $request, Tenant $tenant): array
    {
        $row = Invoicing::integration($tenant->id);
        $settings = Invoicing::settingsOf($row);
        $saved = self::credentials($row);
        $provider = $row && $row->provider === 'nubefact' ? 'nubefact' : 'simulated';

        $branches = DB::table('branches')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->when(Access::ownBranchLimit($request->user()), fn ($q, $id) => $q->where('id', $id))
            ->orderBy('name')
            ->get(['id', 'name']);

        $pending = Invoicing::electronicReady()
            ? DB::table('invoices')->where('tenant_id', $tenant->id)->where('status', 'issued')->where('sunat_status', 'pending')->where('doc_type', '!=', Invoicing::TICKET)->count()
            : 0;

        return [
            'module_on'   => $tenant->hasModule('invoicing'),
            'provider'    => $provider,
            'active'      => Invoicing::provider($tenant)->key(),
            'mode'        => $provider === 'nubefact' ? ($row->mode === 'production' ? 'production' : 'sandbox') : null,
            'saved'       => $provider === 'nubefact' && $saved ? [
                'route'    => self::maskRoute($saved['route'] ?? null),
                'token'    => isset($saved['token']) ? str_repeat('•', 8) . mb_substr($saved['token'], -4) : null,
                'saved_at' => Carbon::parse($row->updated_at)->toIso8601String(),
            ] : null,
            'test'        => isset($settings['tested_at']) ? ['ok' => (bool) ($settings['test_ok'] ?? false), 'message' => $settings['test_message'] ?? null, 'at' => $settings['tested_at']] : null,
            'providers'   => collect(Invoicing::PROVIDERS)->map(fn ($p, $key) => ['key' => $key, 'label' => $p['label']])->values(),
            'series'      => Invoicing::companySeries($tenant->id),
            'defaults'    => Invoicing::DEFAULT_SERIES,
            'branch_series_ready' => Invoicing::branchSeriesReady(),
            'electronic_ready'    => Invoicing::electronicReady(),
            'branches'    => $branches->map(fn ($b) => ['id' => $b->id, 'name' => $b->name, 'series' => (object) Invoicing::branchSeries($b->id)])->values(),
            'pending'     => $pending,
            'void_days'   => Invoicing::VOID_DAYS,
            'can_edit_all_branches' => !Access::ownBranchLimit($request->user()),
        ];
    }

    private function store(Tenant $tenant, ?object $row, array $values, array $settings): void
    {
        $values['settings'] = json_encode($settings);
        $values['updated_at'] = now();

        if ($row) {
            DB::table('tenant_integrations')->where('id', $row->id)->update($values);
        } else {
            DB::table('tenant_integrations')->insert($values + [
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'kind' => 'invoicing',
                'provider' => $values['provider'] ?? 'simulated', 'mode' => $values['mode'] ?? 'simulated', 'status' => 'active', 'created_at' => now(),
            ]);
        }
    }

    public function save(Request $request)
    {
        $tenant = $this->tenant($request, 'settings.edit');

        $validated = $request->validate([
            'provider' => ['required', Rule::in(array_keys(Invoicing::PROVIDERS))],
            'mode'     => ['nullable', Rule::in(['sandbox', 'production'])],
            'route'    => 'nullable|string|max:300',
            'token'    => 'nullable|string|max:300',
        ]);

        $row = Invoicing::integration($tenant->id);
        $settings = Invoicing::settingsOf($row);
        $old = self::credentials($row);
        $before = $row?->provider ?? 'simulated';

        if ($validated['provider'] === 'simulated') {
            $values = ['provider' => 'simulated', 'mode' => 'simulated', 'status' => 'active', 'credentials' => null];
            unset($settings['tested_at'], $settings['test_ok'], $settings['test_message']);
            $changes = $before !== 'simulated' ? ['invoicing_provider' => 'Simulación'] : [];
        } else {
            $route = trim((string) ($validated['route'] ?? '')) ?: ($old['route'] ?? '');
            $token = trim((string) ($validated['token'] ?? '')) ?: ($old['token'] ?? '');

            if ($route === '' || $token === '') {
                throw ValidationException::withMessages(['route' => 'Copia la ruta y el token que te da Nubefact (en tu cuenta: API → Integración).']);
            }

            if (!NubefactInvoiceProvider::validRoute($route)) {
                throw ValidationException::withMessages(['route' => 'La ruta debe ser la dirección que te da Nubefact y empezar con https:// (por ejemplo, https://api.nubefact.com/api/v1/...).']);
            }

            $mode = $validated['mode'] ?? ($row && $row->provider === 'nubefact' && $row->mode !== 'simulated' ? $row->mode : 'sandbox');
            $values = [
                'provider'    => 'nubefact',
                'mode'        => $mode,
                'status'      => 'active',
                'credentials' => Crypt::encryptString(json_encode(['route' => $route, 'token' => $token])),
            ];

            if ($route !== ($old['route'] ?? null) || $token !== ($old['token'] ?? null)) {
                unset($settings['tested_at'], $settings['test_ok'], $settings['test_message']);
            }

            $changes = array_filter([
                'invoicing_provider' => $before !== 'nubefact' ? 'Nubefact' : null,
                'invoicing_route'    => trim((string) ($validated['route'] ?? '')) !== '' ? 'Cambiada' : null,
                'invoicing_token'    => trim((string) ($validated['token'] ?? '')) !== '' ? 'Cambiado' : null,
                'invoicing_mode'     => ($row?->mode ?? null) !== $mode ? ($mode === 'production' ? 'Producción' : 'Pruebas') : null,
            ]);
        }

        $this->store($tenant, $row, $values, $settings);

        if ($changes) {
            Audit::log($request, 'settings.changed', [
                'tenant'       => $tenant->id,
                'entity_type'  => 'settings',
                'entity_label' => 'Facturación electrónica',
                'before'       => isset($changes['invoicing_provider']) ? ['invoicing_provider' => $before === 'nubefact' ? 'Nubefact' : 'Simulación'] : [],
                'after'        => $changes,
            ]);
        }

        return response()->json($this->payload($request, $tenant));
    }

    public function test(Request $request)
    {
        $tenant = $this->tenant($request, 'settings.edit');
        $row = Invoicing::integration($tenant->id);
        $provider = Invoicing::provider($tenant);
        $result = $provider->test(Invoicing::companySeries($tenant->id));

        if ($row) {
            $settings = Invoicing::settingsOf($row);
            $settings['test_ok'] = $result['ok'];
            $settings['test_message'] = $result['message'];
            $settings['tested_at'] = now()->toIso8601String();
            DB::table('tenant_integrations')->where('id', $row->id)->update(['settings' => json_encode($settings)]);
        }

        return response()->json(['ok' => $result['ok'], 'message' => $result['message']] + $this->payload($request, $tenant));
    }

    private static function cleanSeries(array $input, bool $required, string $prefix): array
    {
        $clean = [];

        foreach (Invoicing::DEFAULT_SERIES as $key => $default) {
            $value = mb_strtoupper(trim((string) ($input[$key] ?? '')));

            if ($value === '') {
                if ($required) {
                    $value = $default;
                } else {
                    continue;
                }
            }

            if (!preg_match(Invoicing::SERIES_PATTERNS[$key], $value)) {
                $letter = str_contains($key, 'factura') ? 'F' : 'B';
                throw ValidationException::withMessages([$prefix . $key => "La serie «{$value}» no sirve: son 4 letras o números y empieza con {$letter} (por ejemplo, {$letter}001)."]);
            }

            $clean[$key] = $value;
        }

        return $clean;
    }

    public function saveSeries(Request $request)
    {
        $tenant = $this->tenant($request, 'settings.edit');
        $validated = $request->validate([
            'series'            => 'nullable|array',
            'branches'          => 'nullable|array',
            'branches.*.id'     => 'required|uuid',
            'branches.*.series' => 'nullable|array',
        ]);

        $limit = Access::ownBranchLimit($request->user());
        $row = Invoicing::integration($tenant->id);
        $settings = Invoicing::settingsOf($row);
        $beforeCompany = Invoicing::companySeries($tenant->id);
        $changes = [];

        if (array_key_exists('series', $validated) && !$limit) {
            $company = self::cleanSeries($validated['series'] ?? [], true, 'series.');

            if ($company !== $beforeCompany) {
                $settings['series'] = $company;
                $this->store($tenant, $row, $row ? [] : ['provider' => 'simulated', 'mode' => 'simulated'], $settings);
                $changes['Empresa'] = implode(', ', $company);
            }
        }

        if (!empty($validated['branches'])) {
            if (!Invoicing::branchSeriesReady()) {
                throw ValidationException::withMessages(['branches' => 'Falta actualizar la base de datos para las series por sede (php artisan migrate).']);
            }

            foreach ($validated['branches'] as $index => $item) {
                $branch = DB::table('branches')->where('tenant_id', $tenant->id)->where('id', $item['id'])->first(['id', 'name']);
                abort_unless($branch, 404);
                abort_if($limit && $limit !== $branch->id, 403, 'Solo puedes cambiar las series de tu sede.');

                $series = self::cleanSeries($item['series'] ?? [], false, "branches.{$index}.series.");

                if ($series !== Invoicing::branchSeries($branch->id)) {
                    DB::table('branches')->where('id', $branch->id)->update(['invoice_series' => json_encode((object) $series), 'updated_at' => now()]);
                    $changes[$branch->name] = $series ? implode(', ', $series) : 'Las de la empresa';
                }
            }
        }

        if ($changes) {
            Audit::log($request, 'settings.changed', [
                'tenant'       => $tenant->id,
                'entity_type'  => 'settings',
                'entity_label' => 'Series de comprobantes',
                'after'        => ['invoice_series' => collect($changes)->map(fn ($v, $k) => "{$k}: {$v}")->implode(' · ')],
            ]);
        }

        return response()->json($this->payload($request, $tenant));
    }
}
