<?php

namespace Tests;

use App\Models\Branch;
use App\Models\BusinessType;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PlatformMail;
use App\Support\TenantProvisioner;
use Database\Seeders\EssentialDataSeeder;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use ReflectionClass;

abstract class TestCase extends BaseTestCase
{
    public const PLAN_ESENCIAL = '11111111-1111-1111-1111-111111111111';
    public const PLAN_PROFESIONAL = '22222222-2222-2222-2222-222222222222';
    public const PLAN_EMPRESARIAL = '33333333-3333-3333-3333-333333333333';

    protected bool $seed = true;

    protected string $seeder = EssentialDataSeeder::class;

    private const CACHED = [
        \App\Models\PlatformSetting::class,
        \App\Models\Service::class,
        \App\Support\Access::class,
        \App\Support\Audit::class,
        \App\Support\Codes::class,
        \App\Support\Commissions::class,
        \App\Support\Currency::class,
        \App\Support\CustomerStages::class,
        \App\Support\PlanBilling::class,
        \App\Support\PlanTransfers::class,
        \App\Support\PlatformMail::class,
        \App\Support\Subscriptions::class,
        \App\Support\Walkin::class,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        self::forgetCaches();
        PlatformMail::$outbox = [];
        Http::preventStrayRequests();
    }

    public function createApplication()
    {
        $app = parent::createApplication();
        $config = $app['config'];
        $database = $config->get('database.connections.' . $config->get('database.default') . '.database');

        if (!str_ends_with((string) $database, '_test')) {
            throw new \RuntimeException("Las pruebas borran la base: solo corren en una terminada en _test, no en \"$database\".");
        }

        return $app;
    }

    public static function forgetCaches(): void
    {
        foreach (self::CACHED as $class) {
            $reflection = new ReflectionClass($class);

            foreach ($reflection->getProperties() as $property) {
                if ($property->isStatic() && $property->hasDefaultValue() && $property->getDeclaringClass()->getName() === $class) {
                    $property->setValue(null, $property->getDefaultValue());
                }
            }
        }

        PlatformMail::$outbox = [];
    }

    protected function company(array $options = []): object
    {
        $slug = $options['business'] ?? 'salon';
        $email = $options['email'] ?? 'duena.' . Str::lower(Str::random(6)) . '@prueba.test';

        $result = TenantProvisioner::provision([
            'name'             => $options['name'] ?? 'Empresa de prueba ' . Str::upper(Str::random(4)),
            'business_type_id' => BusinessType::where('slug', $slug)->value('id'),
            'plan_id'          => $options['plan'] ?? self::PLAN_EMPRESARIAL,
            'start_trial'      => $options['trial'] ?? false,
            'first_name'       => 'Dueña',
            'last_name'        => 'Prueba',
            'email'            => $email,
            'password'         => 'clave-de-prueba-123',
        ]);

        $tenant = $result['tenant'];

        if (array_key_exists('modules_off', $options)) {
            DB::table('tenant_settings')->where('tenant_id', $tenant->id)->update(['modules_off' => json_encode($options['modules_off'])]);
        }

        if (!empty($options['rules'])) {
            $this->setRules($tenant, $options['rules']);
        }

        self::forgetCaches();

        return (object) [
            'tenant' => Tenant::find($tenant->id),
            'owner'  => $result['user'],
            'branch' => Branch::where('tenant_id', $tenant->id)->first(),
        ];
    }

    protected function setRules(Tenant $tenant, array $rules): void
    {
        $current = json_decode((string) DB::table('tenant_settings')->where('tenant_id', $tenant->id)->value('rules'), true) ?: [];
        DB::table('tenant_settings')->where('tenant_id', $tenant->id)->update(['rules' => json_encode(array_merge($current, $rules))]);
        self::forgetCaches();
    }

    protected function as(User|\App\Models\Customer $user): static
    {
        self::forgetCaches();
        $user->unsetRelations();
        Sanctum::actingAs($user, ['*']);

        return $this;
    }

    protected function service(Tenant $tenant, ?string $name = null): object
    {
        return DB::table('services')
            ->where('tenant_id', $tenant->id)
            ->when($name, fn ($q) => $q->where('name', $name))
            ->where('price', '>', 0)
            ->orderBy('name')
            ->first();
    }

    protected function servicePriced(Tenant $tenant, float $price, string $name = 'Servicio de prueba'): object
    {
        $id = (string) Str::uuid();
        $base = (array) DB::table('services')->where('tenant_id', $tenant->id)->where('price', '>', 0)->orderBy('name')->first();
        $copy = array_intersect_key($base, array_flip(['category_id', 'duration_minutes', 'status_id']));

        DB::table('services')->insert($copy + [
            'id' => $id, 'tenant_id' => $tenant->id, 'name' => $name, 'price' => $price,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('services')->where('id', $id)->first();
    }

    protected function customer(Tenant $tenant, string $first = 'Clienta', string $last = 'De Prueba'): \App\Models\Customer
    {
        $id = (string) Str::uuid();

        DB::table('customers')->insert([
            'id' => $id, 'tenant_id' => $tenant->id, 'code' => 'CLI-' . Str::upper(Str::random(6)),
            'first_name' => $first, 'last_name' => $last, 'email' => Str::lower(Str::random(8)) . '@cliente.test',
            'password_hash' => bcrypt('clave-de-prueba-123'), 'must_change_password' => false,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return \App\Models\Customer::find($id);
    }

    protected function staff(object $company, string $role, ?Branch $branch = null, string $name = 'Persona Prueba'): User
    {
        $userId = (string) Str::uuid();
        [$first, $last] = array_pad(explode(' ', $name, 2), 2, 'Prueba');

        User::create([
            'id' => $userId, 'tenant_id' => $company->tenant->id, 'branch_id' => ($branch ?? $company->branch)->id,
            'first_name' => $first, 'last_name' => $last, 'email' => Str::lower(Str::random(8)) . '@equipo.test',
            'password_hash' => bcrypt('clave-de-prueba-123'), 'is_owner' => false, 'status' => 'active',
        ]);

        DB::table('user_roles')->insert([
            'tenant_id' => $company->tenant->id, 'user_id' => $userId,
            'role_id' => DB::table('roles')->where('tenant_id', $company->tenant->id)->where('slug', $role)->value('id'),
        ]);

        return User::find($userId);
    }

    protected function professional(object $company, string $name = 'Profesional Prueba', float $percent = 0, ?Branch $branch = null): object
    {
        $branch ??= $company->branch;
        $user = $this->staff($company, 'professional', $branch, $name);

        $id = (string) Str::uuid();
        DB::table('professionals')->insert([
            'id' => $id, 'tenant_id' => $company->tenant->id, 'user_id' => $user->id, 'display_name' => $name,
            'commission_percent' => $percent, 'created_at' => now(), 'updated_at' => now(),
        ]);

        foreach (range(0, 6) as $weekday) {
            DB::table('professional_schedules')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $company->tenant->id, 'professional_id' => $id,
                'branch_id' => $branch->id, 'weekday' => $weekday, 'starts_local' => '08:00', 'ends_local' => '20:00',
            ]);
        }

        $row = DB::table('professionals')->where('id', $id)->first();
        $row->user = $user;

        return $row;
    }

    protected function branch(object $company, string $name = 'Sede Dos'): Branch
    {
        return Branch::create([
            'id' => (string) Str::uuid(), 'tenant_id' => $company->tenant->id,
            'company_id' => DB::table('companies')->where('tenant_id', $company->tenant->id)->value('id'),
            'name' => $name, 'timezone' => 'America/Lima', 'status' => 'active',
        ]);
    }

    protected function workday(Tenant $tenant, int $daysAhead = 3): \Illuminate\Support\Carbon
    {
        $day = now('America/Lima')->startOfDay()->addDays($daysAhead);

        while (DB::table('holidays')->where('tenant_id', $tenant->id)->where('date', $day->format('Y-m-d'))->exists()) {
            $day->addDay();
        }

        return $day;
    }

    protected function at(Tenant $tenant, string $time = '10:00', int $daysAhead = 3): string
    {
        return $this->workday($tenant, $daysAhead)->format('Y-m-d') . 'T' . $time;
    }

    protected function book(object $company, object $customer, object $service, object $professional, string $startsAt, array $extra = []): TestResponse
    {
        return $this->as($extra['as'] ?? $company->owner)->postJson('/api/appointments', array_diff_key($extra, ['as' => 1]) + [
            'customer_id' => $customer->id, 'service_id' => $service->id, 'professional_id' => $professional->id,
            'branch_id' => ($extra['branch_id'] ?? $company->branch->id), 'starts_at' => $startsAt,
        ]);
    }

    protected function openCash(object $company, float $opening = 100): array
    {
        $register = DB::table('cash_registers')->where('tenant_id', $company->tenant->id)->where('branch_id', $company->branch->id)->first();

        if (!$register) {
            $response = $this->as($company->owner)->postJson('/api/cash/registers', ['branch_id' => $company->branch->id, 'name' => 'Caja principal'])->assertCreated();
            $register = DB::table('cash_registers')->where('id', $response->json('id'))->first();
        }

        return $this->as($company->owner)
            ->postJson("/api/cash/registers/{$register->id}/open", ['opening_amount' => $opening])
            ->assertCreated()
            ->json();
    }

    protected function sell(object $company, array $items, array $extra = []): array
    {
        $response = $this->as($company->owner)->postJson('/api/pos/sales', ['branch_id' => $company->branch->id, 'items' => $items] + $extra);
        $response->assertCreated();

        return $response->json('sale');
    }

    protected function pay(object $company, string $saleId, array $data): TestResponse
    {
        $defaults = in_array($data['method'] ?? 'cash', ['card', 'transfer', 'yape', 'plin'], true) ? ['reference' => 'OP-' . Str::random(6)] : [];

        return $this->as($company->owner)->postJson("/api/sales/{$saleId}/pay", $data + $defaults);
    }

    protected function sale(string $id): object
    {
        return DB::table('sales')->where('id', $id)->first();
    }

    protected function cashSession(string $id): array
    {
        return \App\Support\Cash::present(DB::table('cash_sessions')->where('id', $id)->first());
    }
}
