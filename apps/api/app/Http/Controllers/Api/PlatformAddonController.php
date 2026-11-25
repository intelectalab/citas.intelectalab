<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Addons;
use App\Support\Audit;
use App\Support\DataRetention;
use App\Support\PlatformWhatsApp;
use App\Support\Rls;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlatformAddonController extends Controller
{
    public function index()
    {
        abort_unless(Addons::ready(), 503, Addons::NOT_READY);

        return response()->json([
            'addons'  => Addons::catalog(false)->map(fn ($addon) => Addons::presentAdmin($addon))->values(),
            'limits'  => collect(Addons::LIMITS)->map(fn ($label, $key) => ['key' => $key, 'label' => $label])->values(),
            'modules' => DB::table('saas_modules')->orderBy('name')->get(['key', 'name']),
        ]);
    }

    private function validated(Request $request, ?string $id = null): array
    {
        $values = $request->validate([
            'name'               => 'required|string|max:120',
            'code'               => ['nullable', 'string', 'max:40', 'regex:/^[a-z0-9-]+$/', Rule::unique('saas_addons', 'code')->ignore($id)],
            'description'        => 'nullable|string|max:300',
            'monthly_price'      => 'required|numeric|min:0|max:99999',
            'currency'           => 'nullable|string|size:3',
            'module_key'         => ['nullable', 'string', Rule::exists('saas_modules', 'key')],
            'limit_key'          => ['nullable', Rule::in(array_keys(Addons::LIMITS))],
            'limit_amount'       => 'nullable|integer|min:0|max:100000',
            'max_quantity'       => 'required|integer|min:1|max:100',
            'status'             => 'required|in:active,inactive',
            'presentation_order' => 'nullable|integer|min:0|max:999',
        ], [
            'name.required'          => 'Escribe el nombre del complemento.',
            'monthly_price.required' => 'Escribe el precio mensual.',
            'code.regex'             => 'Usa solo letras minúsculas, números y guiones (por ejemplo sede-adicional).',
            'code.unique'            => 'Ya hay un complemento con ese código.',
        ]);

        if (empty($values['module_key']) && (empty($values['limit_key']) || empty($values['limit_amount']))) {
            throw ValidationException::withMessages(['limit_key' => 'Elige qué amplía el complemento (por ejemplo «Sedes» y cuántas) o qué módulo enciende.']);
        }

        return [
            'name'               => trim($values['name']),
            'code'               => $values['code'] ?? null,
            'description'        => $values['description'] ?? null,
            'monthly_price'      => round((float) $values['monthly_price'], 2),
            'currency'           => strtoupper($values['currency'] ?? 'PEN'),
            'module_key'         => $values['module_key'] ?? null,
            'limit_key'          => $values['limit_key'] ?? null,
            'limit_amount'       => (int) ($values['limit_key'] ?? null ? ($values['limit_amount'] ?? 0) : 0),
            'max_quantity'       => (int) $values['max_quantity'],
            'status'             => $values['status'],
            'presentation_order' => (int) ($values['presentation_order'] ?? 0),
        ];
    }

    public function store(Request $request)
    {
        abort_unless(Addons::ready(), 503, Addons::NOT_READY);
        $values = $this->validated($request);
        $values['code'] ??= Str::slug($values['name']);

        if (DB::table('saas_addons')->where('code', $values['code'])->exists()) {
            $values['code'] .= '-' . Str::lower(Str::random(4));
        }

        $id = (string) Str::uuid();
        DB::table('saas_addons')->insert($values + ['id' => $id, 'created_at' => now(), 'updated_at' => now()]);
        Audit::log($request, 'platform.addon_created', ['entity_type' => 'addon', 'entity_id' => $id, 'entity_label' => $values['name'], 'after' => ['name' => $values['name'], 'price_monthly' => $values['monthly_price']]]);

        return response()->json(Addons::presentAdmin(DB::table('saas_addons')->where('id', $id)->first()), 201);
    }

    public function update(Request $request, string $addon)
    {
        abort_unless(Addons::ready(), 503, Addons::NOT_READY);
        $row = DB::table('saas_addons')->where('id', $addon)->first();
        abort_if(!$row, 404, 'Ese complemento no existe.');
        $values = $this->validated($request, $addon);
        $values['code'] ??= $row->code;
        DB::table('saas_addons')->where('id', $addon)->update($values + ['updated_at' => now()]);
        [$before, $after] = Audit::changed(
            ['name' => $row->name, 'price_monthly' => (float) $row->monthly_price, 'status' => $row->status, 'max_quantity' => (int) $row->max_quantity],
            ['name' => $values['name'], 'price_monthly' => $values['monthly_price'], 'status' => $values['status'], 'max_quantity' => $values['max_quantity']],
        );
        Audit::log($request, 'platform.addon_updated', ['entity_type' => 'addon', 'entity_id' => $addon, 'entity_label' => $values['name'], 'before' => $before ?: null, 'after' => $after ?: null]);

        return response()->json(Addons::presentAdmin(DB::table('saas_addons')->where('id', $addon)->first()));
    }

    public function destroy(Request $request, string $addon)
    {
        abort_unless(Addons::ready(), 503, Addons::NOT_READY);
        $row = DB::table('saas_addons')->where('id', $addon)->first();
        abort_if(!$row, 404, 'Ese complemento no existe.');

        if (Rls::bypass(fn () => DB::table('tenant_addons')->where('addon_id', $addon)->exists())) {
            throw ValidationException::withMessages(['addon' => 'Este complemento ya lo contrató alguna empresa: en vez de eliminarlo, desactívalo para que nadie más lo contrate.']);
        }

        DB::table('saas_addons')->where('id', $addon)->delete();
        Audit::log($request, 'platform.addon_deleted', ['entity_type' => 'addon', 'entity_id' => $addon, 'entity_label' => $row->name]);

        return response()->json(['message' => 'Complemento eliminado.']);
    }

    public function retention()
    {
        return response()->json(DataRetention::present());
    }

    public function saveRetention(Request $request)
    {
        $values = $request->validate([
            'enabled'     => 'required|boolean',
            'days'        => 'required|integer|min:30|max:3650',
            'warn_days'   => 'required|array|min:1|max:4',
            'warn_days.*' => 'integer|min:1|max:365',
        ], ['days.min' => 'El plazo mínimo es de 30 días.']);

        if (collect($values['warn_days'])->contains(fn ($day) => $day >= $values['days'])) {
            throw ValidationException::withMessages(['warn_days' => 'Los avisos deben salir antes del borrado: usa menos días que el plazo.']);
        }

        $before = DataRetention::config();
        $after = DataRetention::save($values);
        [$b, $a] = Audit::changed(['enabled' => $before['enabled'], 'days' => $before['days'], 'text' => implode(', ', $before['warn_days'])], ['enabled' => $after['enabled'], 'days' => $after['days'], 'text' => implode(', ', $after['warn_days'])]);
        Audit::log($request, 'platform.retention_changed', ['before' => $b ?: null, 'after' => $a ?: null]);

        return response()->json(DataRetention::present());
    }

    public function whatsapp()
    {
        return response()->json(PlatformWhatsApp::present());
    }

    public function saveWhatsapp(Request $request)
    {
        $values = $request->validate([
            'provider'        => ['required', Rule::in(array_keys(PlatformWhatsApp::PROVIDERS))],
            'token'           => 'nullable|string|max:600',
            'phone_number_id' => 'nullable|string|max:40',
            'language'        => ['nullable', 'string', 'max:10', 'regex:/^[a-z]{2}(_[A-Z]{2})?$/'],
            'templates'       => 'nullable|array',
            'templates.*'     => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9_]+$/'],
            'active'          => 'boolean',
        ], ['templates.*.regex' => 'El nombre de la plantilla va en minúsculas, con números o guiones bajos, tal como está en Meta.']);

        if ($values['provider'] === 'simulated' && app()->isProduction()) {
            throw ValidationException::withMessages(['provider' => 'En el servidor real no se puede probar sin enviar.']);
        }

        $old = PlatformWhatsApp::config();
        $hasToken = trim((string) ($values['token'] ?? '')) !== '' || (($old['provider'] ?? null) === $values['provider'] && !empty($old['token']));

        if ($values['provider'] !== 'simulated' && !$hasToken) {
            throw ValidationException::withMessages(['token' => 'Pega el token de acceso de WhatsApp.']);
        }

        if ($values['provider'] === 'cloud' && empty($values['phone_number_id'])) {
            throw ValidationException::withMessages(['phone_number_id' => 'Escribe el identificador del número (Phone number ID) que aparece en Meta.']);
        }

        $present = PlatformWhatsApp::save($values);
        Audit::log($request, 'platform.whatsapp_changed', ['before' => ['text' => $old ? (PlatformWhatsApp::PROVIDERS[$old['provider']] ?? $old['provider']) : 'Sin configurar'], 'after' => ['text' => PlatformWhatsApp::PROVIDERS[$values['provider']], 'enabled' => $present['enabled']]]);

        return response()->json($present);
    }

    public function testWhatsapp()
    {
        return response()->json(PlatformWhatsApp::test());
    }

    public function destroyWhatsapp(Request $request)
    {
        PlatformWhatsApp::remove();
        Audit::log($request, 'platform.whatsapp_changed', ['before' => ['text' => 'Configurado'], 'after' => ['text' => 'Quitado']]);

        return response()->json(PlatformWhatsApp::present());
    }
}
