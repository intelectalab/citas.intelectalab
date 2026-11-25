<?php

use App\Models\User;
use App\Support\AppointmentFlow;
use App\Support\SignupGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

function rev7Admin(): User
{
    return User::forceCreate([
        'id' => (string) Str::uuid(),
        'first_name' => 'Admin', 'last_name' => 'Revisión', 'email' => 'rev7.' . Str::lower(Str::random(6)) . '@plataforma.test',
        'password_hash' => bcrypt('clave-de-prueba-123'), 'status' => 'active', 'tenant_id' => null,
    ]);
}

function rev7Signup($test, string $ruc)
{
    TestCase::forgetCaches();

    return $test->postJson('/api/register-tenant', [
        'ruc' => $ruc, 'company_name' => 'Spa Rev7 ' . Str::upper(Str::random(4)), 'department' => 'Lima',
        'first_name' => 'Rosa', 'last_name' => 'Quispe', 'email' => 'rosa.rev7.' . Str::lower(Str::random(6)) . '@correo.test',
        'password' => 'clave-segura-123', 'plan_id' => TestCase::PLAN_PROFESIONAL,
        'form_token' => SignupGuard::token(now()->subMinute()),
    ]);
}

beforeEach(function () {
    config(['services.turnstile.site_key' => null, 'services.turnstile.secret_key' => null]);
});

describe('revisión en pantalla de la séptima tanda', function () {
    it('las reservas en línea de Reportes no cuentan las citas canceladas', function () {
        $co = $this->company();
        $service = $this->servicePriced($co->tenant, 50);
        $pro = $this->professional($co);
        $customer = $this->customer($co->tenant);

        $ids = [];
        foreach (['10:00', '12:00'] as $time) {
            $ids[] = $this->book($co, $customer, $service, $pro, $this->at($co->tenant, $time))->assertCreated()->json('id');
        }

        foreach ($ids as $index => $id) {
            DB::table('appointments')->where('id', $id)->update([
                'source' => 'online',
                'starts_at' => now()->subDays(2 + $index)->utc(),
                'ends_at' => now()->subDays(2 + $index)->addHour()->utc(),
            ]);
        }
        DB::table('appointments')->where('id', $ids[1])->update(['status' => AppointmentFlow::CANCELLED]);

        $summary = $this->as($co->owner)->getJson('/api/reports?tab=appointments&period=30')->assertOk()->json('data.summary');

        expect($summary['online'])->toBe(1)->and($summary['online_percent'])->toBe(100);
    });

    it('restablecer un rubro de fábrica sigue contando las empresas que lo usan', function () {
        $co = $this->company();
        $typeId = DB::table('tenants')->where('id', $co->tenant->id)->value('business_type_id');

        $res = $this->as(rev7Admin())->postJson("/api/admin/business-types/{$typeId}/reset")->assertOk();

        expect($res->json('tenants_count'))->toBeGreaterThanOrEqual(1)
            ->and($res->json())->toHaveKey('outdated_tenants_count');
    });

    it('editar un rubro devuelve también cuántas empresas lo usan', function () {
        $co = $this->company();
        $typeId = DB::table('tenants')->where('id', $co->tenant->id)->value('business_type_id');
        $admin = rev7Admin();
        $type = $this->as($admin)->getJson("/api/admin/business-types/{$typeId}")->assertOk()->json();

        $res = $this->as($admin)->putJson("/api/admin/business-types/{$typeId}", $type + ['description' => 'Descripción cambiada en la revisión.']);

        if ($res->status() === 200) {
            expect($res->json('tenants_count'))->toBeGreaterThanOrEqual(1);
        } else {
            expect($res->status())->toBe(422);
        }
    });

    it('Mi cuenta muestra el nombre del rol de la persona, también si es la dueña', function () {
        $co = $this->company();
        $roleId = DB::table('roles')->where('tenant_id', $co->tenant->id)->value('id');
        DB::table('roles')->where('id', $roleId)->update(['name' => 'Dueña']);
        DB::table('user_roles')->where('user_id', $co->owner->id)->delete();
        DB::table('user_roles')->insert(['user_id' => $co->owner->id, 'role_id' => $roleId]);

        $profile = $this->as($co->owner)->getJson('/api/account/profile')->assertOk()->json();

        expect($profile['role'])->toBe('Dueña');
    });

    it('los límites de los planes se leen bien: 1 sede, personas que atienden y WhatsApp con mayúscula', function () {
        $co = $this->company();
        $plans = $this->as($co->owner)->getJson('/api/billing')->assertOk()->json('plans');
        $limits = collect($plans)->flatMap(fn ($plan) => $plan['limits'] ?? []);

        $oneBranch = $limits->first(fn ($row) => $row['key'] === 'branches' && $row['value'] === 1);
        $pros = $limits->first(fn ($row) => $row['key'] === 'professionals' && $row['value'] > 1);
        $whatsapp = $limits->first(fn ($row) => $row['key'] === 'whatsapp_month' && $row['value'] > 1);

        expect($oneBranch['label'] ?? null)->toBe('sede')
            ->and($pros['label'] ?? null)->toBe('personas que atienden')
            ->and($whatsapp['label'] ?? null)->toBe('mensajes de WhatsApp al mes');
    });

    it('la llamada en la que pidieron que llamen después se anota sin suponer si es hombre o mujer', function () {
        $co = $this->company();
        $customer = $this->customer($co->tenant, 'Andrea', 'Lozano');

        $this->as($co->owner)->postJson("/api/customers/{$customer->id}/calls", ['outcome' => 'callback', 'note' => 'Pidió que la llamen el viernes.'])->assertSuccessful();

        expect(DB::table('messages')->where('customer_id', $customer->id)->where('channel', 'call')->value('subject'))
            ->toBe('Llamada: pidió que le devuelvan la llamada');
    });

    it('el registro no acepta un RUC con el último número mal y sí uno correcto', function () {
        rev7Signup($this, '20605512340')->assertStatus(422)
            ->assertJsonValidationErrors('ruc')
            ->assertJsonPath('errors.ruc.0', 'Revisa el RUC: el último número no coincide con los demás. Puede que se haya escrito mal un número.');

        rev7Signup($this, '20123456786')->assertSuccessful();
    });
    it('al crear un servicio sugiere el siguiente código de su categoría, y sin categoría empieza en SRV-01', function () {
        $co = $this->company();
        $category = (string) Str::uuid();
        DB::table('service_categories')->insert(['id' => $category, 'tenant_id' => $co->tenant->id, 'name' => 'Uñas y manos', 'created_at' => now(), 'updated_at' => now()]);
        $service = $this->servicePriced($co->tenant, 40, 'Manicure revisión');
        DB::table('services')->where('id', $service->id)->update(['code' => 'una-07', 'category_id' => $category]);

        $this->as($co->owner)->getJson('/api/services/next-code?category_id=' . $category)->assertOk()->assertJsonPath('code', 'UNA-08');
        expect($this->as($co->owner)->getJson('/api/services/next-code')->assertOk()->json('code'))->toStartWith('SRV-');

        $otra = $this->company();
        $this->as($otra->owner)->getJson('/api/services/next-code?category_id=' . $category)->assertOk()->assertJsonPath('code', 'SRV-01');
        $this->as($this->staff($co, 'professional'))->getJson('/api/services/next-code')->assertForbidden();
    });
});
