<?php

use App\Support\Rls;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__ . '/helpers.php';

afterEach(function () {
    superRole();
    Rls::clear();
});

describe('la base protege a cada empresa', function () {
    it('toda tabla con empresa tiene la protección forzada y su política', function () {
        $missing = DB::select(<<<'SQL'
SELECT c.table_name
FROM information_schema.columns c
JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name
JOIN pg_class k ON k.relname = c.table_name AND k.relnamespace = current_schema()::regnamespace
WHERE c.table_schema = current_schema() AND c.column_name = 'tenant_id' AND t.table_type = 'BASE TABLE'
  AND (NOT k.relrowsecurity OR NOT k.relforcerowsecurity
       OR NOT EXISTS (SELECT 1 FROM pg_policies p WHERE p.schemaname = current_schema() AND p.tablename = c.table_name AND p.policyname = 'tenant_isolation'))
SQL);

        expect(array_column($missing, 'table_name'))->toBe([], 'Estas tablas tienen tenant_id pero no la protección por empresa. En su migración agrega: \App\Support\Rls::protect(\'tabla\');');
        expect(DB::table('pg_policies')->where('policyname', 'tenant_isolation')->count())->toBeGreaterThan(100);
    });

    it('con la empresa A fijada no se ve nada de B, sin empresa no se ve nada y no se puede escribir para otra', function () {
        $a = $this->company();
        $b = $this->company();
        $this->customer($a->tenant, 'Ana');
        $this->customer($b->tenant, 'Beto');

        appRole();
        expect(DB::selectOne('select current_user as who, rolsuper, rolbypassrls from pg_roles where rolname = current_user'))
            ->who->toBe('erp_app_test')->rolsuper->toBeFalse()->rolbypassrls->toBeFalse();

        Rls::tenant($a->tenant->id);
        DB::table('customers')->insert(newCustomer($a->tenant->id, 'Carla'));
        expect(visible('customers', $a->tenant->id))->toBe(2)
            ->and(visible('customers', $b->tenant->id))->toBe(0)
            ->and(visible('branches', $b->tenant->id))->toBe(0)
            ->and(visible('services', $b->tenant->id))->toBe(0)
            ->and(DB::table('customers')->pluck('tenant_id')->unique()->all())->toBe([$a->tenant->id]);

        expect(DB::table('customers')->where('tenant_id', $b->tenant->id)->update(['first_name' => 'Cambiado']))->toBe(0)
            ->and(DB::table('customers')->where('tenant_id', $b->tenant->id)->delete())->toBe(0);

        Rls::tenant($b->tenant->id);
        expect(visible('customers', $a->tenant->id))->toBe(0)
            ->and(visible('appointments', $a->tenant->id))->toBe(0)
            ->and(visible('customers'))->toBe(1)
            ->and(failsInsert(fn () => DB::table('customers')->insert(newCustomer($a->tenant->id, 'Intruso'))))->toBeTrue()
            ->and(failsInsert(fn () => DB::table('customers')->where('tenant_id', $b->tenant->id)->update(['tenant_id' => $a->tenant->id])))->toBeTrue();

        Rls::clear();
        expect(visible('customers'))->toBe(0)
            ->and(visible('branches'))->toBe(0)
            ->and(visible('users'))->toBe(0)
            ->and(visible('tenant_settings'))->toBe(0)
            ->and(failsInsert(fn () => DB::table('customers')->insert(newCustomer($a->tenant->id, 'Sin empresa'))))->toBeTrue();

        Rls::platform();
        expect(visible('customers', $a->tenant->id))->toBe(2)
            ->and(visible('customers', $b->tenant->id))->toBe(1);
    });

    it('los catálogos de fábrica se comparten, pero los de una empresa no', function () {
        $a = $this->company();
        $b = $this->company();

        appRole();
        Rls::tenant($a->tenant->id);
        $shared = DB::table('roles')->whereNull('tenant_id')->count();
        $statuses = DB::table('statuses')->whereNull('tenant_id')->count();

        expect(DB::table('roles')->where('tenant_id', $b->tenant->id)->count())->toBe(0)
            ->and(DB::table('roles')->where('tenant_id', $a->tenant->id)->count())->toBeGreaterThan(0)
            ->and($statuses)->toBeGreaterThan(0)
            ->and(failsInsert(fn () => DB::table('roles')->insert(['id' => (string) Str::uuid(), 'tenant_id' => null, 'name' => 'Rol global', 'slug' => 'rol-global-' . Str::random(4)])))->toBeTrue();

        superRole();
        expect(DB::table('roles')->whereNull('tenant_id')->count())->toBe($shared);
    });

    it('la plataforma y las tareas del sistema ven todas las empresas', function () {
        $a = $this->company();
        $b = $this->company();

        appRole();
        Rls::tenant($a->tenant->id);
        Rls::commandStarted();
        expect(Rls::current())->toBe(['tenant' => null, 'bypass' => true])
            ->and(visible('tenant_settings', $a->tenant->id) + visible('tenant_settings', $b->tenant->id))->toBe(2);
        Rls::commandFinished();
        expect(Rls::current())->toBe(['tenant' => $a->tenant->id, 'bypass' => false])
            ->and(visible('tenant_settings', $b->tenant->id))->toBe(0)
            ->and(Rls::bypass(fn () => visible('tenant_settings', $b->tenant->id)))->toBe(1)
            ->and(visible('tenant_settings', $b->tenant->id))->toBe(0);
    });

    it('si una transacción se deshace, la empresa fijada sigue igual', function () {
        $a = $this->company();
        $b = $this->company();

        appRole();
        Rls::tenant($a->tenant->id);

        try {
            DB::transaction(function () use ($b) {
                Rls::tenant($b->tenant->id);
                Rls::tenant(null);
                throw new RuntimeException('falla a propósito');
            });
        } catch (RuntimeException) {
        }

        expect(Rls::current()['tenant'])->toBeNull()
            ->and(visible('customers'))->toBe(0);

        Rls::tenant($a->tenant->id);
        expect(DB::selectOne("select current_setting('app.tenant_id', true) as id")->id)->toBe($a->tenant->id);
    });
});

describe('la aplicación funciona con un usuario de base sin permisos especiales', function () {
    it('entra, lista clientes, crea una cita y el horario ocupado se rechaza', function () {
        $a = $this->company();
        $b = $this->company();
        $service = $this->servicePriced($a->tenant, 50);
        $pro = $this->professional($a);
        $mine = $this->customer($a->tenant, 'Lucía');
        $other = $this->customer($b->tenant, 'Ajena');
        $startsAt = $this->at($a->tenant, '10:00');

        appRole();

        $login = $this->postJson('/api/login', ['email' => $a->owner->email, 'password' => 'clave-de-prueba-123'])->assertOk();
        $token = $login->json('token') ?? $login->json('access_token');
        expect($token)->toBeString();

        $headers = ['Authorization' => 'Bearer ' . $token];
        $this->app['auth']->forgetGuards();
        $names = collect($this->getJson('/api/customers', $headers)->assertOk()->json('data'))->pluck('first_name');
        expect($names)->toContain('Lucía')->not->toContain('Ajena');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/customers/' . $other->id, $headers)->assertNotFound();

        $this->app['auth']->forgetGuards();
        $body = ['customer_id' => $mine->id, 'service_id' => $service->id, 'professional_id' => $pro->id, 'branch_id' => $a->branch->id, 'starts_at' => $startsAt];
        $created = $this->postJson('/api/appointments', $body, $headers)->assertCreated();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/appointments', $body + ['customer_id' => $mine->id], $headers)->assertStatus(409);

        $this->app['auth']->forgetGuards();
        expect($this->postJson('/api/appointments', ['customer_id' => $other->id] + $body, $headers)->status())->toBeIn([404, 422]);

        superRole();
        expect(DB::table('appointments')->where('id', $created->json('id'))->value('tenant_id'))->toBe($a->tenant->id)
            ->and(DB::table('appointments')->where('tenant_id', $a->tenant->id)->count())->toBe(1);
    });

    it('la reserva pública por su dirección funciona y solo usa datos de esa empresa', function () {
        $a = $this->company();
        $this->company();
        $slug = DB::table('tenants')->where('id', $a->tenant->id)->value('slug');
        $service = $this->servicePriced($a->tenant, 80, 'Manicure');
        DB::table('services')->where('id', $service->id)->update(['duration_minutes' => 60, 'online_booking' => true, 'requires_deposit' => false]);
        $pro = $this->professional($a, 'Ana Torres');
        \Illuminate\Support\Facades\RateLimiter::clear('write|' . $slug . '|127.0.0.1');
        $startsAt = $this->at($a->tenant, '10:00');

        appRole();

        $catalog = $this->getJson("/api/public/booking/{$slug}/catalog")->assertOk()->json();
        expect(collect($catalog['services'])->pluck('id'))->toContain($service->id);

        $this->postJson("/api/public/booking/{$slug}/appointments", [
            'service_id' => $service->id, 'branch_id' => $a->branch->id, 'professional_id' => $pro->id, 'starts_at' => $startsAt,
            'first_name' => 'Rosa', 'last_name' => 'Huamán', 'document_number' => '45678912', 'phone' => '987 654 321',
            'email' => 'rosa.huaman@cliente.test', 'accept_privacy' => true, 'request_key' => (string) Str::uuid(),
        ])->assertSuccessful();

        superRole();
        expect(DB::table('appointments')->where('tenant_id', $a->tenant->id)->count())->toBe(1)
            ->and(DB::table('customers')->where('tenant_id', $a->tenant->id)->where('first_name', 'Rosa')->count())->toBe(1);
    });

    it('la API con clave, el portal del cliente y la consola de la plataforma funcionan', function () {
        $a = $this->company(['modules_off' => []]);
        $b = $this->company();
        $client = $this->customer($a->tenant, 'Lucía', 'Quispe');
        $plain = $this->as($a->owner)->postJson('/api/integrations/api/keys', ['name' => 'Página web', 'scopes' => ['customers' => 'write']])->assertCreated()->json('plain');
        $platform = \App\Models\User::forceCreate([
            'id' => (string) Str::uuid(), 'first_name' => 'Admin', 'last_name' => 'Plataforma', 'email' => 'saas.' . Str::lower(Str::random(6)) . '@plataforma.test',
            'password_hash' => bcrypt('clave-de-prueba-123'), 'status' => 'active', 'tenant_id' => null,
        ]);
        $this->app['auth']->forgetGuards();

        appRole();

        $this->withHeader('Authorization', 'Bearer ' . $plain)->postJson('/api/v1/customers', ['first_name' => 'Rosa', 'last_name' => 'Huamán', 'email' => 'rosa@cliente.test'])->assertCreated();
        $this->withHeader('Authorization', 'Bearer ' . $plain)->getJson('/api/v1/customers?search=huaman')->assertOk()->assertJsonPath('meta.total', 1);
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        superRole();
        $client->unsetRelations();
        appRole();
        $this->as($client)->getJson('/api/portal/overview')->assertOk();

        superRole();
        $platform->unsetRelations();
        appRole();
        $this->app['auth']->forgetGuards();
        $rows = collect($this->as($platform)->getJson('/api/tenants')->assertOk()->json())->keyBy('id');
        expect($rows->keys())->toContain($a->tenant->id)->toContain($b->tenant->id)
            ->and($rows[$a->tenant->id]['users'])->not->toBeEmpty()
            ->and($rows[$b->tenant->id]['users'])->not->toBeEmpty();
    });

    it('una ruta pública sin empresa no ve datos de ninguna', function () {
        $a = $this->company();

        appRole();
        $this->getJson('/api/public/booking/no-existe-esta-empresa')->assertNotFound();
        expect(Rls::current())->toBe(['tenant' => null, 'bypass' => \Tests\TestCase::strictRls()])
            ->and(Rls::within(null, false, fn () => visible('tenant_settings', $a->tenant->id)))->toBe(0);
    });
});
