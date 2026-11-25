<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

$api = realpath(__DIR__ . '/../../apps/api');
require $api . '/vendor/autoload.php';
$app = require $api . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) config('database.connections.' . config('database.default') . '.database');

if (!str_ends_with($database, '_test') || $database === 'erp_saas') {
    fwrite(STDERR, "La base {$database} no termina en _test.\n");
    exit(1);
}

$tenant = DB::table('tenants')->where('slug', 'bella-aura')->first();
$other = DB::table('tenants')->where('slug', 'barberia-don-filo')->first();

if (!$tenant || !$other) {
    fwrite(STDERR, "Faltan las empresas de muestra.\n");
    exit(1);
}

$password = 'E2e-' . Str::random(20);
$hash = Hash::make($password);

$owner = DB::table('users')->where('email', 'admin@bellaaura.pe')->first();
$reception = DB::table('users')->where('email', 'fiorella.campos@bellaaura.pe')->first();
$platform = DB::table('users')->where('email', 'admin@tusaas.com')->first();
$customer = DB::table('customers')->where('tenant_id', $tenant->id)->whereNotNull('email')->whereNull('deleted_at')->orderBy('created_at')->first();

$userIds = array_filter([$owner?->id, $reception?->id, $platform?->id]);
$userUpdate = ['password_hash' => $hash];

foreach (['must_change_password' => false, 'mfa_mode' => 'off'] as $column => $value) {
    if (\Illuminate\Support\Facades\Schema::hasColumn('users', $column)) {
        $userUpdate[$column] = $value;
    }
}

DB::table('users')->whereIn('id', $userIds)->update($userUpdate);
DB::table('customers')->where('id', $customer->id)->update(['password_hash' => $hash, 'must_change_password' => false]);

$setRules = function (string $tenantId, array $rules): void {
    $current = json_decode((string) DB::table('tenant_settings')->where('tenant_id', $tenantId)->value('rules'), true) ?: [];
    DB::table('tenant_settings')->where('tenant_id', $tenantId)->update(['rules' => json_encode(array_merge($current, $rules))]);
};

$emailSimulated = function (string $tenantId): void {
    if (!DB::table('tenant_integrations')->where('tenant_id', $tenantId)->where('kind', 'email')->exists()) {
        DB::table('tenant_integrations')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'kind' => 'email', 'provider' => 'simulated', 'mode' => 'simulated',
            'settings' => json_encode(['from_address' => 'recorridos@empresa.test', 'from_name' => 'Recorridos']), 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
};

$journey = function (string $slug, string $ownerEmail, string $serviceName, int $customers = 1, bool $withPet = false) use ($hash, $userUpdate) {
    $t = DB::table('tenants')->where('slug', $slug)->first();
    $o = DB::table('users')->where('email', $ownerEmail)->first();

    if (!$t || !$o) {
        return null;
    }

    DB::table('users')->where('id', $o->id)->update($userUpdate);

    $people = DB::table('customers')->where('tenant_id', $t->id)->whereNull('deleted_at')->whereNotNull('email')
        ->when($withPet, fn ($q) => $q->whereExists(fn ($s) => $s->select(DB::raw(1))->from('customer_dependents as d')->whereColumn('d.customer_id', 'customers.id')->where('d.status', 'active')))
        ->orderBy('code')->limit($customers)->get(['id', 'first_name', 'last_name', 'email', 'phone']);

    return [
        'tenant'    => ['id' => $t->id, 'slug' => $t->slug, 'name' => $t->name, 'code' => $t->code],
        'owner'     => ['email' => $o->email],
        'branch'    => DB::table('branches')->where('tenant_id', $t->id)->where('status', 'active')->orderBy('created_at')->first(['id', 'name']),
        'service'   => DB::table('services')->where('tenant_id', $t->id)->where('name', $serviceName)->first(['id', 'name', 'price']),
        'customers' => $people->map(fn ($c) => [
            'id' => $c->id, 'name' => trim($c->first_name . ' ' . $c->last_name), 'email' => $c->email,
            'pet' => $withPet ? DB::table('customer_dependents')->where('customer_id', $c->id)->where('status', 'active')->orderBy('name')->first(['id', 'name']) : null,
        ])->values()->all(),
    ];
};

$journeys = [
    'clinic' => $journey('centro-medico-integral', 'admin@luxuryspa.pe', 'Consulta especializada', 1),
    'dental' => $journey('clinica-dentalplus', 'admin@dentalplus.pe', 'Control de ortodoncia', 1),
    'vet'    => $journey('veterinaria-huellitas', 'admin@huellitas.pe', 'Vacunación', 1, true),
    'barber' => $journey('barberia-don-filo', 'admin@donfilo.pe', 'Corte clásico', 3),
];

if ($journeys['clinic']) {
    $setRules($journeys['clinic']['tenant']['id'], ['records.require_on_complete' => true, 'cash.review_over' => 2]);
}

if ($journeys['barber']) {
    $barberId = $journeys['barber']['tenant']['id'];
    $setRules($barberId, [
        'penalties.enabled' => true, 'cancel.late_fee_percent' => 25, 'cancel.window_hours' => 96, 'noshow.fee_percent' => 50,
        'waitlist.enabled' => true, 'waitlist.notify_mode' => 'all', 'walkin.max_wait' => 720, 'walkin.self_checkin' => true,
    ]);
    $emailSimulated($barberId);

    DB::table('professional_schedules')->where('tenant_id', $barberId)->where('weekday', now('America/Lima')->dayOfWeek)
        ->update(['starts_local' => '00:00', 'ends_local' => '23:59']);

    if (\Illuminate\Support\Facades\Schema::hasTable('branch_hours')) {
        DB::table('branch_hours')->where('tenant_id', $barberId)->where('weekday', now('America/Lima')->dayOfWeek)
            ->update(['opens_local' => '00:00', 'closes_local' => '23:59']);
    }

    $noShow = $journeys['barber']['customers'][2] ?? null;

    if ($noShow) {
        DB::table('customer_consents')->updateOrInsert(
            ['tenant_id' => $barberId, 'customer_id' => $noShow['id'], 'channel' => 'email'],
            ['granted' => true, 'granted_at' => now()],
        );

        $barberTenant = \App\Models\Tenant::find($barberId);
        $subscription = $barberTenant->activeSubscription;

        if ($subscription && !$barberTenant->hasModule('automations')) {
            $withAutomations = \App\Models\SaaSPlan::all()->first(fn ($plan) => in_array('automations', $plan->moduleKeys(), true));

            if ($withAutomations) {
                DB::table('subscriptions')->where('id', $subscription->id)->update(['plan_id' => $withAutomations->id]);
            }
        }

        \App\Support\Automations::rules($barberTenant);
        DB::table('automation_rules')->where('tenant_id', $barberId)->where('trigger_event', 'no_show')
            ->update(['is_active' => true, 'enabled_at' => now()->subHour(), 'delay_minutes' => 0, 'updated_at' => now()]);

        $service = DB::table('services')->where('id', $journeys['barber']['service']->id)->first();
        $professionals = DB::table('professionals')->where('tenant_id', $barberId)->pluck('id');
        $journeys['barber']['no_show_appointment'] = null;

        foreach ([3, 4, 5, 6, 26, 27, 28] as $hoursAgo) {
            foreach ($professionals as $professionalId) {
                $start = now()->subHours($hoursAgo)->startOfHour();

                try {
                    $created = \App\Models\Appointment::create([
                        'tenant_id' => $barberId, 'code' => \App\Models\Appointment::nextCode($barberId),
                        'branch_id' => $journeys['barber']['branch']->id, 'customer_id' => $noShow['id'], 'service_id' => $service->id,
                        'professional_id' => $professionalId, 'starts_at' => $start, 'ends_at' => $start->copy()->addMinutes((int) $service->duration_minutes ?: 30),
                        'status' => 'confirmed', 'source' => 'staff', 'price' => $service->price, 'confirmed_at' => now()->subDays(2), 'created_by' => DB::table('users')->where('email', $journeys['barber']['owner']['email'])->value('id'),
                    ]);
                    $journeys['barber']['no_show_appointment'] = $created->id;
                    break 2;
                } catch (\Illuminate\Database\QueryException) {
                    continue;
                }
            }
        }
    }
}

$otherAppointment = DB::table('appointments')->where('tenant_id', $other->id)->value('id');
$otherCustomer = DB::table('customers')->where('tenant_id', $other->id)->value('id');

echo json_encode([
    'password'  => $password,
    'database'  => $database,
    'tenant'    => ['id' => $tenant->id, 'slug' => $tenant->slug, 'name' => $tenant->name],
    'owner'     => ['email' => $owner->email, 'name' => trim($owner->first_name . ' ' . $owner->last_name)],
    'reception' => $reception ? ['email' => $reception->email] : null,
    'platform'  => ['email' => $platform->email],
    'customer'  => ['email' => $customer->email, 'first_name' => $customer->first_name, 'last_name' => $customer->last_name],
    'service'   => DB::table('services')->where('tenant_id', $tenant->id)->where('name', 'Corte de dama')->first(['id', 'name', 'price']),
    'branch'    => DB::table('branches')->where('tenant_id', $tenant->id)->orderBy('created_at')->first(['id', 'name']),
    'customer_id' => $customer->id,
    'agenda_customers' => DB::table('customers')->where('tenant_id', $tenant->id)->where('id', '!=', $customer->id)->whereNull('deleted_at')
        ->whereNotNull('document_number')->orderBy('code')->limit(2)->get(['id', 'first_name', 'last_name'])
        ->map(fn ($c) => ['id' => $c->id, 'name' => trim($c->first_name . ' ' . $c->last_name)])->values(),
    'other'     => ['slug' => $other->slug, 'appointment_id' => $otherAppointment, 'customer_id' => $otherCustomer],
    'journeys'  => $journeys,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
