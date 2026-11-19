<?php

use App\Support\Rls;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__ . '/helpers.php';

beforeEach(function () {
    $this->co = $this->company();
    $this->send = fn (string $url, array $body, ?string $key = null, $who = null) => $this->as($who ?? $this->co->owner)
        ->withHeaders($key ? ['Idempotency-Key' => $key] : [])
        ->postJson($url, $body);
});

afterEach(function () {
    superRole();
    Rls::clear();
});

function customersNamed(string $tenantId, string $name): int
{
    return DB::table('customers')->where('tenant_id', $tenantId)->where('first_name', $name)->count();
}

describe('repetir una solicitud', function () {
    it('sin la cabecera todo funciona como antes y cada envío crea un registro', function () {
        ($this->send)('/api/customers', ['first_name' => 'Rosa', 'last_name' => 'Huamán'])->assertCreated();
        ($this->send)('/api/customers', ['first_name' => 'Rosa', 'last_name' => 'Huamán'])->assertCreated();

        expect(customersNamed($this->co->tenant->id, 'Rosa'))->toBe(2)
            ->and(DB::table('idempotency_keys')->count())->toBe(0);
    });

    it('con la misma clave devuelve la respuesta original y no duplica', function () {
        $key = (string) Str::uuid();
        $first = ($this->send)('/api/customers', ['first_name' => 'Rosa', 'last_name' => 'Huamán'], $key)->assertCreated();
        $again = ($this->send)('/api/customers', ['last_name' => 'Huamán', 'first_name' => 'Rosa'], $key)->assertCreated();

        expect($again->json('id'))->toBe($first->json('id'))
            ->and($again->headers->get('Idempotent-Replayed'))->toBe('true')
            ->and($first->headers->get('Idempotent-Replayed'))->toBeNull()
            ->and(customersNamed($this->co->tenant->id, 'Rosa'))->toBe(1);

        $row = DB::table('idempotency_keys')->where('key', $key)->first();
        expect($row->tenant_id)->toBe($this->co->tenant->id)
            ->and($row->response_status)->toBe(201)
            ->and((string) $row->response_body)->not->toContain('Rosa')
            ->and(\Illuminate\Support\Carbon::parse($row->expires_at)->diffInHours(now(), true))->toBeGreaterThan(23.9);
    });

    it('la misma clave con otro contenido responde 409 IDEMPOTENCY_MISMATCH', function () {
        $key = (string) Str::uuid();
        ($this->send)('/api/customers', ['first_name' => 'Rosa', 'last_name' => 'Huamán'], $key)->assertCreated();

        ($this->send)('/api/customers', ['first_name' => 'Carla', 'last_name' => 'Huamán'], $key)
            ->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_MISMATCH');
        ($this->send)('/api/branches', ['first_name' => 'Rosa', 'last_name' => 'Huamán'], $key)
            ->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_MISMATCH');

        expect(customersNamed($this->co->tenant->id, 'Carla'))->toBe(0);
    });

    it('si el envío falla no se guarda y se puede corregir con la misma clave', function () {
        $key = (string) Str::uuid();
        ($this->send)('/api/customers', ['last_name' => 'Sin nombre'], $key)->assertUnprocessable();
        ($this->send)('/api/customers', ['last_name' => 'Sin nombre'], $key)->assertUnprocessable();

        expect(DB::table('idempotency_keys')->where('key', $key)->exists())->toBeFalse();
    });

    it('la clave de otra persona no devuelve la respuesta guardada', function () {
        $key = (string) Str::uuid();
        $reception = $this->staff($this->co, 'reception');
        ($this->send)('/api/customers', ['first_name' => 'Rosa', 'last_name' => 'Huamán'], $key)->assertCreated();
        $other = ($this->send)('/api/customers', ['first_name' => 'Rosa', 'last_name' => 'Huamán'], $key, $reception);

        expect($other->headers->get('Idempotent-Replayed'))->toBeNull();
    });

    it('una clave con caracteres raros se rechaza', function () {
        ($this->send)('/api/customers', ['first_name' => 'Rosa', 'last_name' => 'Huamán'], 'no vale!')
            ->assertStatus(400)->assertJsonPath('code', 'IDEMPOTENCY_KEY_INVALID');
    });
});

describe('operaciones con dinero', function () {
    it('cobrar dos veces con la misma clave registra un solo pago', function () {
        $this->openCash($this->co);
        $sale = $this->sell($this->co, [['type' => 'service', 'id' => $this->servicePriced($this->co->tenant, 100)->id]]);
        $key = (string) Str::uuid();

        $first = ($this->send)("/api/sales/{$sale['id']}/pay", ['method' => 'cash', 'amount' => 100], $key)->assertOk();
        $again = ($this->send)("/api/sales/{$sale['id']}/pay", ['method' => 'cash', 'amount' => 100], $key)->assertOk();

        expect($again->json())->toBe($first->json())
            ->and(DB::table('payments')->where('sale_id', $sale['id'])->count())->toBe(1)
            ->and(money(DB::table('payments')->where('sale_id', $sale['id'])->sum('amount')))->toBe(100.0);
    });

    it('registrar la misma venta dos veces con la misma clave crea una sola venta', function () {
        $this->openCash($this->co);
        $line = ['type' => 'service', 'id' => $this->servicePriced($this->co->tenant, 100)->id];
        $key = (string) Str::uuid();
        $before = DB::table('sales')->where('tenant_id', $this->co->tenant->id)->count();

        ($this->send)('/api/pos/sales', ['branch_id' => $this->co->branch->id, 'items' => [$line]], $key)->assertCreated();
        ($this->send)('/api/pos/sales', ['branch_id' => $this->co->branch->id, 'items' => [$line]], $key)->assertCreated();

        expect(DB::table('sales')->where('tenant_id', $this->co->tenant->id)->count())->toBe($before + 1);
    });

    it('la cita del panel con su clave de envío y la cabecera no se duplica', function () {
        $service = $this->servicePriced($this->co->tenant, 50);
        $pro = $this->professional($this->co);
        $client = $this->customer($this->co->tenant);
        $body = ['customer_id' => $client->id, 'service_id' => $service->id, 'professional_id' => $pro->id, 'branch_id' => $this->co->branch->id, 'starts_at' => $this->at($this->co->tenant, '11:00'), 'request_key' => (string) Str::uuid()];
        $key = (string) Str::uuid();

        $first = ($this->send)('/api/appointments', $body, $key)->assertCreated();
        ($this->send)('/api/appointments', $body, $key)->assertCreated()->assertJsonPath('id', $first->json('id'));
        ($this->send)('/api/appointments', $body)->assertCreated()->assertJsonPath('id', $first->json('id'));

        expect(DB::table('appointments')->where('tenant_id', $this->co->tenant->id)->count())->toBe(1);
    });
});

describe('otras entradas', function () {
    it('la API con clave también respeta la cabecera', function () {
        $co = $this->company(['modules_off' => []]);
        $plain = $this->as($co->owner)->postJson('/api/integrations/api/keys', ['name' => 'Web', 'scopes' => ['customers' => 'write']])->assertCreated()->json('plain');
        $this->app['auth']->forgetGuards();
        $key = (string) Str::uuid();
        $call = fn (array $body) => $this->withHeaders(['Authorization' => 'Bearer ' . $plain, 'Idempotency-Key' => $key])->postJson('/api/v1/customers', $body);

        $first = $call(['first_name' => 'Rosa', 'last_name' => 'Huamán'])->assertCreated();
        $call(['first_name' => 'Rosa', 'last_name' => 'Huamán'])->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        $call(['first_name' => 'Otra', 'last_name' => 'Huamán'])->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_MISMATCH');

        expect(customersNamed($co->tenant->id, 'Rosa'))->toBe(1)
            ->and(DB::table('idempotency_keys')->where('key', $key)->value('scope'))->toStartWith('api:');
    });

    it('funciona con el usuario de base sin permisos especiales', function () {
        appRole();
        $key = (string) Str::uuid();
        ($this->send)('/api/customers', ['first_name' => 'Rosa', 'last_name' => 'Huamán'], $key)->assertCreated();
        ($this->send)('/api/customers', ['first_name' => 'Rosa', 'last_name' => 'Huamán'], $key)->assertCreated()->assertHeader('Idempotent-Replayed', 'true');

        superRole();
        expect(customersNamed($this->co->tenant->id, 'Rosa'))->toBe(1);
    });
});
