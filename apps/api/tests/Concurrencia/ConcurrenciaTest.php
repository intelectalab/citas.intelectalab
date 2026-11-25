<?php

use Database\Seeders\EssentialDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

function aLaVez(array $jobs): array
{
    $dir = storage_path('framework/testing/concurrencia-' . Str::random(8));
    @mkdir($dir, 0777, true);
    $go = $dir . DIRECTORY_SEPARATOR . 'go';
    $procs = [];

    foreach (array_values($jobs) as $i => $job) {
        $file = $dir . DIRECTORY_SEPARATOR . "job-{$i}.json";
        file_put_contents($file, json_encode($job + ['go' => $go]));
        $pipes = [];
        $process = proc_open([PHP_BINARY, '-d', 'memory_limit=512M', __DIR__ . DIRECTORY_SEPARATOR . 'worker.php', $file], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), getenv());
        $procs[] = [$process, $pipes, $file];
    }

    $limit = microtime(true) + 120;

    while (microtime(true) < $limit && collect($procs)->contains(fn ($p) => !file_exists($p[2] . '.ready'))) {
        usleep(20000);
    }

    file_put_contents($go, '1');
    $results = [];

    foreach ($procs as [$process, $pipes, $file]) {
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);
        $decoded = json_decode(trim((string) $out), true);

        if (!is_array($decoded)) {
            throw new RuntimeException('Un proceso paralelo no respondió: ' . mb_substr($out . ' ' . $err, 0, 2000));
        }

        $results[] = $decoded;
    }

    foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);

    return $results;
}

function estados(array $results): array
{
    $codes = array_map(fn ($r) => $r['status'], $results);
    sort($codes);

    return $codes;
}

beforeEach(function () {
    if (!Schema::hasTable('business_types') || !DB::table('business_types')->exists()) {
        $this->artisan('migrate:fresh', ['--seed' => true, '--seeder' => EssentialDataSeeder::class, '--force' => true]);
    } else {
        $this->artisan('migrate', ['--force' => true]);
    }

    self::forgetCaches();
    $this->co = $this->company();
    $this->token = $this->co->owner->createToken('concurrencia', ['*'])->plainTextToken;
    $this->job = fn (string $method, string $uri, array $body = []) => ['method' => $method, 'uri' => $uri, 'body' => $body, 'token' => $this->token];
});

afterEach(function () {
    RefreshDatabaseState::$migrated = false;
});

it('dos reservas del mismo horario a la vez: una entra y la otra recibe 409', function () {
    $servicio = $this->servicePriced($this->co->tenant, 50, 'Corte a la vez');
    $pro = $this->professional($this->co);
    $inicio = $this->at($this->co->tenant, '11:00', 4);
    $clientas = [$this->customer($this->co->tenant, 'Ana', 'Primera'), $this->customer($this->co->tenant, 'Bea', 'Segunda'), $this->customer($this->co->tenant, 'Carla', 'Tercera')];

    $results = aLaVez(array_map(fn ($c) => ($this->job)('POST', '/api/appointments', [
        'customer_id' => $c->id, 'service_id' => $servicio->id, 'professional_id' => $pro->id,
        'branch_id' => $this->co->branch->id, 'starts_at' => $inicio,
    ]), $clientas));

    $rechazos = collect($results)->where('status', 409)->pluck('body.code')->unique()->values()->all();

    expect(estados($results))->toBe([201, 409, 409])
        ->and(array_diff($rechazos, ['SLOT_TAKEN', 'SLOT_UNAVAILABLE']))->toBe([])
        ->and(DB::table('appointments')->where('professional_id', $pro->id)->whereNotIn('status', ['cancelled', 'no_show'])->count())->toBe(1);
});

it('dos cobros a la vez de la misma venta no la pagan dos veces', function () {
    $servicio = $this->servicePriced($this->co->tenant, 80, 'Tinte a la vez');
    $this->as($this->co->owner);
    $venta = $this->sell($this->co, [['type' => 'service', 'id' => $servicio->id]]);

    $results = aLaVez([
        ($this->job)('POST', "/api/sales/{$venta['id']}/pay", ['method' => 'card', 'amount' => 80, 'reference' => 'OP-A1']),
        ($this->job)('POST', "/api/sales/{$venta['id']}/pay", ['method' => 'card', 'amount' => 80, 'reference' => 'OP-B2']),
    ]);

    $pagado = DB::table('payments')->where('sale_id', $venta['id'])->where('status', 'confirmed')->sum('amount');

    expect(collect($results)->where('status', 200)->count())->toBe(1)
        ->and(money($pagado))->toBe(80.0)
        ->and(DB::table('payments')->where('sale_id', $venta['id'])->count())->toBe(1)
        ->and(DB::table('sales')->where('id', $venta['id'])->value('status'))->toBe('paid');
});

it('dos consumos a la vez de la última sesión de un paquete: uno falla', function () {
    $servicio = $this->servicePriced($this->co->tenant, 50, 'Masaje a la vez');
    $clienta = $this->customer($this->co->tenant);
    $plan = $this->as($this->co->owner)->postJson('/api/package-plans', [
        'name' => 'Un masaje', 'price' => 50, 'validity_days' => 90,
        'booking_mode' => 'free', 'frequency' => 'weekly', 'professional_mode' => 'any',
        'late_policy' => 'lose', 'refund_policy' => 'unused_normal',
        'items' => [['service_id' => $servicio->id, 'sessions' => 1]],
    ])->assertCreated()->json();
    $venta = $this->sell($this->co, [['type' => 'package', 'id' => $plan['id']]], ['customer_id' => $clienta->id]);
    $this->pay($this->co, $venta['id'], ['method' => 'card', 'amount' => $venta['total']])->assertOk();
    $paquete = DB::table('customer_packages')->where('sale_id', $venta['id'])->first();

    $uso = ($this->job)('POST', "/api/packages/{$paquete->id}/usages", ['service_id' => $servicio->id, 'note' => 'Sesión usada a la vez']);
    $results = aLaVez([$uso, $uso, $uso]);

    expect(collect($results)->filter(fn ($r) => $r['status'] < 300)->count())->toBe(1)
        ->and(DB::table('package_usages')->where('customer_package_id', $paquete->id)->count())->toBe(1)
        ->and((int) DB::table('customer_package_items')->where('customer_package_id', $paquete->id)->value('sessions_used'))->toBe(1);
});

it('varias boletas emitidas a la vez salen con correlativos seguidos, sin huecos ni repetidos', function () {
    $servicio = $this->servicePriced($this->co->tenant, 59, 'Manicure a la vez');
    $this->as($this->co->owner);
    $ventas = collect(range(1, 5))->map(fn () => $this->sell($this->co, [['type' => 'service', 'id' => $servicio->id]]))->all();

    $results = aLaVez(array_map(fn ($v, $i) => ($this->job)('POST', "/api/sales/{$v['id']}/pay", [
        'method' => 'card', 'amount' => 59, 'reference' => "OP-C{$i}", 'doc_type' => 'boleta',
    ]), $ventas, array_keys($ventas)));

    $numeros = DB::table('invoices')->where('tenant_id', $this->co->tenant->id)->where('doc_type', 'boleta')->orderBy('number')->pluck('number')->map(fn ($n) => (int) $n)->all();

    expect(estados($results))->toBe([200, 200, 200, 200, 200])
        ->and($numeros)->toHaveCount(5)
        ->and(array_unique($numeros))->toHaveCount(5)
        ->and(max($numeros) - min($numeros))->toBe(4);
});

it('dos cajas a la vez con un cupón de un solo uso: solo una lo canjea', function () {
    $servicio = $this->servicePriced($this->co->tenant, 50, 'Corte con cupón a la vez');
    DB::table('coupons')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $this->co->tenant->id, 'code' => 'SOLOUNO', 'discount_type' => 'amount', 'discount_value' => 10,
        'max_uses' => 1, 'used_count' => 0, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $venta = ($this->job)('POST', '/api/pos/sales', ['branch_id' => $this->co->branch->id, 'items' => [['type' => 'service', 'id' => $servicio->id]], 'coupon_code' => 'SOLOUNO']);

    $results = aLaVez([$venta, $venta, $venta]);

    expect(collect($results)->filter(fn ($r) => $r['status'] < 300)->count())->toBe(1)
        ->and((int) DB::table('coupons')->where('code', 'SOLOUNO')->where('tenant_id', $this->co->tenant->id)->value('used_count'))->toBe(1)
        ->and(DB::table('coupon_redemptions')->where('tenant_id', $this->co->tenant->id)->count())->toBe(1);
});

it('dos cobros a la vez de la última unidad de un producto: uno falla y el stock no queda en negativo', function () {
    $this->as($this->co->owner)->postJson('/api/products', ['name' => 'Shampoo a la vez', 'price' => 30, 'cost' => 10, 'kind' => 'both'])->assertCreated();
    $producto = DB::table('products')->where('tenant_id', $this->co->tenant->id)->where('name', 'Shampoo a la vez')->first();
    $this->as($this->co->owner)->getJson('/api/inventory')->assertOk();
    $almacen = DB::table('warehouses')->where('tenant_id', $this->co->tenant->id)->where('branch_id', $this->co->branch->id)->value('id');
    $this->as($this->co->owner)->postJson('/api/inventory/movements', ['mode' => 'entry', 'warehouse_id' => $almacen, 'product_id' => $producto->id, 'quantity' => 1])->assertCreated();
    $ventas = [
        $this->sell($this->co, [['type' => 'product', 'id' => $producto->id, 'quantity' => 1]]),
        $this->sell($this->co, [['type' => 'product', 'id' => $producto->id, 'quantity' => 1]]),
    ];

    $results = aLaVez(array_map(fn ($v, $i) => ($this->job)('POST', "/api/sales/{$v['id']}/pay", ['method' => 'card', 'amount' => 30, 'reference' => "OP-S{$i}"]), $ventas, array_keys($ventas)));

    expect(estados($results))->toBe([200, 422])
        ->and((float) DB::table('stock_balances')->where('product_id', $producto->id)->sum('quantity'))->toBe(0.0)
        ->and(DB::table('sales')->whereIn('id', array_column($ventas, 'id'))->where('status', 'paid')->count())->toBe(1);
});
