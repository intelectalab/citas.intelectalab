<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AppointmentFlow;
use App\Support\Audit;
use App\Support\SampleData;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class OnboardingController extends Controller
{
    private function tenant(Request $request): Tenant
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 403, 'Esta cuenta no pertenece a una empresa.');

        return $tenant->loadMissing(['settings', 'activeSubscription.plan']);
    }

    public function show(Request $request)
    {
        $user = $request->user();
        $tenant = $this->tenant($request);

        if (!$user->isTenantAdmin()) {
            return response()->json(['visible' => false, 'steps' => [], 'done' => 0, 'total' => 0]);
        }

        $steps = $this->steps($tenant, $user);
        $done = count(array_filter($steps, fn (array $step) => $step['done']));
        $hiddenReady = Schema::hasColumn('tenant_settings', 'onboarding_hidden_at');
        $hidden = $hiddenReady && DB::table('tenant_settings')->where('tenant_id', $tenant->id)->whereNotNull('onboarding_hidden_at')->exists();

        return response()->json([
            'visible'      => !$hidden && $steps !== [] && $done < count($steps),
            'can_hide'     => $hiddenReady,
            'steps'        => $steps,
            'done'         => $done,
            'total'        => count($steps),
            'next'         => collect($steps)->firstWhere('done', false)['key'] ?? null,
            'sample_data'  => $user->allows('settings.edit') ? SampleData::status($tenant) : null,
        ]);
    }

    public function hide(Request $request)
    {
        $user = $request->user();
        $tenant = $this->tenant($request);
        abort_unless($user->isTenantAdmin(), 403, 'Solo el dueño o un administrador puede ocultar los primeros pasos.');

        if (!Schema::hasColumn('tenant_settings', 'onboarding_hidden_at')) {
            return response()->json(['message' => 'Todavía no se puede ocultar: falta actualizar la base de datos de tu sistema.', 'code' => 'NOT_READY'], 409);
        }

        DB::table('tenant_settings')->where('tenant_id', $tenant->id)->update(['onboarding_hidden_at' => now()]);
        Audit::log($request, 'onboarding.hidden', ['tenant' => $tenant->id, 'entity_type' => 'tenant', 'entity_id' => $tenant->id, 'entity_label' => $tenant->name]);

        return response()->json(['visible' => false]);
    }

    public function sampleStatus(Request $request)
    {
        return response()->json(SampleData::status($this->tenant($request)));
    }

    public function createSample(Request $request)
    {
        $user = $request->user();
        $tenant = $this->tenant($request);
        abort_unless($user->isTenantAdmin(), 403, 'Solo el dueño o un administrador puede cargar datos de ejemplo.');

        if (!SampleData::ready()) {
            return response()->json(['message' => 'Los datos de ejemplo todavía no están listos: falta actualizar la base de datos de tu sistema.', 'code' => 'NOT_READY'], 409);
        }

        if (SampleData::status($tenant)['loaded']) {
            return response()->json(['message' => 'Ya tienes datos de ejemplo cargados. Bórralos primero si quieres cargarlos otra vez.', 'code' => 'ALREADY_LOADED'], 409);
        }

        try {
            $result = SampleData::create($tenant, $user);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'MISSING_DATA'], 422);
        }

        Audit::log($request, 'sample_data.created', ['tenant' => $tenant->id, 'entity_type' => 'tenant', 'entity_id' => $tenant->id, 'entity_label' => $tenant->name, 'after' => $result]);

        return response()->json($result + ['status' => SampleData::status($tenant)], 201);
    }

    public function removeSample(Request $request)
    {
        $user = $request->user();
        $tenant = $this->tenant($request);
        abort_unless($user->isTenantAdmin(), 403, 'Solo el dueño o un administrador puede borrar los datos de ejemplo.');

        if (!SampleData::ready()) {
            return response()->json(['message' => 'Los datos de ejemplo todavía no están listos: falta actualizar la base de datos de tu sistema.', 'code' => 'NOT_READY'], 409);
        }

        $result = SampleData::remove($tenant);
        Audit::log($request, 'sample_data.removed', ['tenant' => $tenant->id, 'entity_type' => 'tenant', 'entity_id' => $tenant->id, 'entity_label' => $tenant->name, 'after' => $result]);

        return response()->json($result + [
            'status'  => SampleData::status($tenant),
            'message' => $result['kept'] > 0
                ? "Se borraron los datos de ejemplo. {$result['kept']} no se pudieron borrar porque ya tienen ventas u otros movimientos: quedaron como están."
                : 'Se borraron todos los datos de ejemplo.',
        ]);
    }

    private function steps(Tenant $tenant, User $user): array
    {
        $vocab = $tenant->vocabulary();
        $active = fn (string $table) => DB::table($table)->where("{$table}.tenant_id", $tenant->id)
            ->where(fn ($q) => $q->whereNull("{$table}.status_id")->orWhereNotIn("{$table}.status_id", DB::table('statuses')->where('slug', 'inactive')->select('id')));

        $company = DB::table('companies')->where('tenant_id', $tenant->id)->first();
        $branches = DB::table('branches')->where('tenant_id', $tenant->id)->where('status', 'active')->count();
        $reminders = false;

        if (\App\Support\Messaging::ready()) {
            $settings = \App\Support\Messaging::settings($tenant->id);
            $reminders = !empty($settings['reminder']['enabled'])
                && (\App\Support\Messaging::emailReady($tenant->id) || (\App\Support\WhatsApp::connected($tenant->id) && \App\Support\WhatsApp::channel($tenant) !== 'email'));
        }

        $steps = [
            [
                'key'   => 'company',
                'title' => 'Datos de la empresa y sedes',
                'hint'  => 'Nombre comercial, RUC, dirección y horario de atención de cada sede.',
                'url'   => '/companies',
                'perm'  => 'company.edit',
                'done'  => $company && trim((string) $company->tax_id) !== '' && $branches > 0,
            ],
            [
                'key'   => 'services',
                'title' => 'Servicios y precios',
                'hint'  => 'Lo que ofreces, cuánto dura y cuánto cuesta.',
                'url'   => '/services',
                'perm'  => 'services.view',
                'done'  => $active('services')->exists(),
            ],
            [
                'key'   => 'team',
                'title' => ($vocab['pros'] ?? 'Profesionales') . ' y horarios',
                'hint'  => 'Quién atiende y en qué horario: de aquí salen los horarios libres.',
                'url'   => '/schedules',
                'perm'  => 'professionals.view',
                'done'  => $active('professionals')->whereExists(fn ($q) => $q->select(DB::raw(1))->from('professional_schedules')->whereColumn('professional_schedules.professional_id', 'professionals.id'))->exists(),
            ],
            [
                'key'   => 'booking',
                'title' => 'Reserva en línea',
                'hint'  => 'Activa tu página de reservas y compártela por WhatsApp y redes.',
                'url'   => '/settings#pagina',
                'perm'  => 'settings.edit',
                'module' => 'booking',
                'done'  => $tenant->hasModule('booking') && (bool) AppointmentFlow::rule($tenant, 'booking.enabled')
                    && DB::table('services')->where('tenant_id', $tenant->id)->where('online_booking', true)->exists(),
            ],
            [
                'key'   => 'reminders',
                'title' => 'Recordatorios automáticos',
                'hint'  => 'Avisos antes de la cita para que no se olviden. Conecta el correo de tu empresa y deja encendido el recordatorio.',
                'url'   => '/settings#correo',
                'perm'  => 'settings.edit',
                'done'  => $reminders,
            ],
            [
                'key'   => 'users',
                'title' => 'Tu equipo',
                'hint'  => 'Invita a recepción y a tu equipo con su propio usuario y permisos.',
                'url'   => '/users',
                'perm'  => 'users.view',
                'done'  => DB::table('users')->where('tenant_id', $tenant->id)->where('status', 'active')->count() > 1,
            ],
            [
                'key'   => 'appointment',
                'title' => 'Primera cita',
                'hint'  => 'Agenda una cita de prueba para ver el recorrido completo.',
                'url'   => '/agenda?nueva=1',
                'perm'  => 'agenda.view',
                'done'  => DB::table('appointments')->where('tenant_id', $tenant->id)->exists(),
            ],
        ];

        return array_values(array_map(
            fn (array $step) => array_diff_key($step, ['perm' => 1, 'module' => 1]),
            array_filter($steps, fn (array $step) => $user->allows($step['perm']) && (empty($step['module']) || $tenant->hasModule($step['module']))),
        ));
    }
}
