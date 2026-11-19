<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

const TABLE_BY_PARAM = [
    'appointment'        => 'appointments',
    'automation'         => 'automation_rules',
    'availability_block' => 'availability_blocks',
    'branch'             => 'branches',
    'campaign'           => 'marketing_campaigns',
    'careRecord'         => 'care_records',
    'register'           => 'cash_registers',
    'session'            => 'cash_sessions',
    'rule'               => 'commission_rules',
    'complaint'          => 'complaints',
    'coupon'             => 'coupons',
    'customer'           => 'customers',
    'holiday'            => 'holidays',
    'order'              => 'purchase_orders',
    'supplier'           => 'suppliers',
    'transfer'           => 'stock_transfers',
    'giftCard'           => 'gift_cards',
    'message'            => 'customer_messages',
    'notification'       => 'user_notifications',
    'plan'               => 'package_plans',
    'package'            => 'customer_packages',
    'link'               => 'payment_links',
    'voucher'            => 'payment_vouchers',
    'sale'               => 'sales',
    'product'            => 'products',
    'professional'       => 'professionals',
    'quote'              => 'quotes',
    'recall'             => 'recalls',
    'record_template'    => 'record_templates',
    'room'               => 'rooms',
    'service_category'   => 'service_categories',
    'service'            => 'services',
    'role'               => 'roles',
    'user'               => 'users',
    'entry'              => 'waitlist_entries',
    'walkin'             => 'walkin_entries',
    'dependent'          => 'customer_dependents',
    'tag'                => 'customer_tags',
    'note'               => 'customer_notes',
    'specialty'          => 'specialties',
    'status'             => 'statuses',
    'item'               => 'quote_items',
    'invoice'            => 'invoices',
    'conversation'       => 'whatsapp_conversations',
    'webhook'            => 'webhook_endpoints',
    'delivery'           => 'webhook_deliveries',
    'key'                => 'api_keys',
    'feed'               => 'calendar_feeds',
    'ticket'             => 'saas_support_tickets',
];

const SKIPPED_PREFIXES = ['api/admin', 'api/portal', 'api/tenants', 'api/saas-plans', 'api/tenant-contacts', 'api/billing', 'api/account'];

function otherTenantId(string $table, string $tenantId): ?string
{
    if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'tenant_id')) {
        return null;
    }

    return DB::table($table)->where('tenant_id', $tenantId)->value('id') ?? fillRow($table, $tenantId);
}

function relatedId(string $column, string $tenantId): ?string
{
    $base = substr($column, 0, -3);
    $candidates = [$base . 's', $base . 'es', preg_replace('/y$/', 'ies', $base), $base, 'customer_' . $base . 's', 'service_' . $base . 's'];

    foreach (array_unique($candidates) as $table) {
        if (!Schema::hasTable($table)) {
            continue;
        }

        $query = DB::table($table);

        if (Schema::hasColumn($table, 'tenant_id')) {
            $query->where('tenant_id', $tenantId);
        }

        if ($id = $query->value('id')) {
            return $id;
        }
    }

    return null;
}

function fillRow(string $table, string $tenantId): ?string
{
    $columns = DB::select('select column_name, data_type, udt_name, is_nullable, column_default from information_schema.columns where table_schema = ? and table_name = ?', ['public', $table]);
    $row = [];

    foreach ($columns as $column) {
        $name = $column->column_name;

        if ($name === 'tenant_id') {
            $row[$name] = $tenantId;
            continue;
        }

        if ($name === 'id') {
            $row[$name] = (string) Str::uuid();
            continue;
        }

        if ($column->is_nullable === 'YES' || $column->column_default !== null) {
            continue;
        }

        $row[$name] = match (true) {
            $column->udt_name === 'uuid' && str_ends_with($name, '_id') => relatedId($name, $tenantId) ?? (string) Str::uuid(),
            $column->udt_name === 'uuid'                                 => (string) Str::uuid(),
            in_array($column->udt_name, ['int2', 'int4', 'int8', 'numeric', 'float8'], true) => 1,
            $column->udt_name === 'bool'                                 => false,
            $column->udt_name === 'date'                                 => now()->toDateString(),
            str_starts_with($column->udt_name, 'timestamp')              => now(),
            in_array($column->udt_name, ['json', 'jsonb'], true)         => '{}',
            str_starts_with($column->udt_name, '_')                      => '{}',
            default                                                      => 'P' . Str::upper(Str::random(8)),
        };
    }

    try {
        DB::transaction(fn () => DB::table($table)->insert($row));

        return $row['id'];
    } catch (\Throwable $e) {
        return null;
    }
}

it('ninguna ruta del panel deja ver, cambiar ni borrar registros de otra empresa', function () {
    $mine = $this->company();
    $other = $this->company();

    $servicio = $this->servicePriced($other->tenant, 50, 'Servicio ajeno');
    DB::table('services')->where('id', $servicio->id)->update(['duration_minutes' => 30]);
    $pro = $this->professional($other, 'Pro Ajena');
    $clienta = $this->customer($other->tenant, 'Clienta', 'Ajena');
    $this->book($other, $clienta, $servicio, $pro, $this->at($other->tenant, '10:00'))->assertCreated();
    $this->openCash($other);
    $this->sell($other, [['type' => 'service', 'id' => $servicio->id, 'quantity' => 1]], ['customer_id' => $clienta->id]);
    DB::table('user_notifications')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $other->tenant->id, 'user_id' => $other->owner->id, 'type' => 'test', 'title' => 'Aviso ajeno', 'created_at' => now()]);

    $leaks = [];
    $checked = 0;
    $skipped = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        if (!str_starts_with($uri, 'api/') || !str_contains($uri, '{') || collect(SKIPPED_PREFIXES)->contains(fn ($p) => str_starts_with($uri, $p))) {
            continue;
        }

        if (!collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_contains($m, 'auth:sanctum'))) {
            continue;
        }

        $params = $route->parameterNames();
        $values = [];

        foreach ($params as $param) {
            $table = TABLE_BY_PARAM[$param] ?? null;
            $id = $table ? otherTenantId($table, $other->tenant->id) : null;

            if (!$id) {
                $skipped[$uri] = $param;
                continue 2;
            }

            $values[$param] = $id;
        }

        $url = '/' . preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => $values[$m[1]] ?? '', $uri);

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $this->as($mine->owner);
            $response = $this->json($method, $url, []);
            $checked++;

            if ($response->getStatusCode() >= 500) {
                $errors[] = "{$method} {$uri} → {$response->getStatusCode()}";
            }

            if ($response->getStatusCode() < 300) {
                $leaks[] = "{$method} {$uri} → {$response->getStatusCode()}";
            }
        }
    }

    expect($checked)->toBeGreaterThan(100)
        ->and($leaks)->toBe([])
        ->and($errors ?? [])->toBe([]);
});

it('ninguna lista del panel muestra registros de otra empresa', function () {
    $mine = $this->company();
    $other = $this->company();

    $servicio = $this->servicePriced($other->tenant, 50, 'Servicio ajeno');
    DB::table('services')->where('id', $servicio->id)->update(['duration_minutes' => 30]);
    $pro = $this->professional($other, 'Pro Ajena');
    $clienta = $this->customer($other->tenant, 'Clienta', 'Ajena');
    $cita = $this->book($other, $clienta, $servicio, $pro, $this->at($other->tenant, '10:00'))->assertCreated()->json('id');
    $this->openCash($other);
    $venta = $this->sell($other, [['type' => 'service', 'id' => $servicio->id, 'quantity' => 1]], ['customer_id' => $clienta->id])['id'];

    $foreign = array_filter([$servicio->id, $pro->id, $clienta->id, $cita, $venta, $other->branch->id]);
    $leaks = [];
    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        if (!in_array('GET', $route->methods(), true) || !str_starts_with($uri, 'api/') || str_contains($uri, '{') || collect(SKIPPED_PREFIXES)->contains(fn ($p) => str_starts_with($uri, $p))) {
            continue;
        }

        if (!collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_contains($m, 'auth:sanctum'))) {
            continue;
        }

        $this->as($mine->owner);
        $response = $this->getJson('/' . $uri);
        $checked++;
        $body = (string) $response->getContent();

        foreach ($foreign as $id) {
            if (str_contains($body, $id)) {
                $leaks[] = "GET {$uri} muestra {$id}";
            }
        }
    }

    expect($checked)->toBeGreaterThan(50)->and($leaks)->toBe([]);
});

it('la API con clave no deja ver, cambiar ni listar registros de otra empresa', function () {
    $mine = $this->company(['modules_off' => []]);
    $other = $this->company(['modules_off' => []]);

    $servicio = $this->servicePriced($other->tenant, 50, 'Servicio ajeno');
    DB::table('services')->where('id', $servicio->id)->update(['duration_minutes' => 30]);
    $pro = $this->professional($other, 'Pro Ajena');
    $clienta = $this->customer($other->tenant, 'Clienta', 'Ajena');
    $cita = $this->book($other, $clienta, $servicio, $pro, $this->at($other->tenant, '10:00'))->assertCreated()->json('id');

    $plain = \App\Support\ApiKeys::generate();
    DB::table('api_keys')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $mine->tenant->id, 'name' => 'Todo', 'prefix' => substr($plain, 0, 12),
        'key_hash' => \App\Support\ApiKeys::hash($plain), 'created_by' => $mine->owner->id, 'created_at' => now(), 'updated_at' => now(),
        'scopes' => json_encode(['appointments' => 'write', 'availability' => 'read', 'customers' => 'write', 'services' => 'read']),
    ]);

    $ids = ['appointment' => $cita, 'customer' => $clienta->id];
    $foreign = [$servicio->id, $pro->id, $clienta->id, $cita, $other->branch->id];
    $leaks = [];
    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        if (!str_starts_with($uri, 'api/v1/')) {
            continue;
        }

        $url = '/' . preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => $ids[$m[1]] ?? Str::uuid(), $uri);

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $data = $method === 'GET' && str_contains($uri, 'availability')
                ? ['service_id' => $servicio->id, 'branch_id' => $other->branch->id, 'date' => now()->addDays(3)->toDateString()]
                : ($method === 'POST' ? ['customer_id' => $clienta->id, 'service_id' => $servicio->id, 'professional_id' => $pro->id, 'branch_id' => $other->branch->id, 'starts_at' => now()->addDays(4)->format('Y-m-d\T10:00')] : []);
            $response = $this->withHeader('Authorization', 'Bearer ' . $plain)->json($method, $method === 'GET' && $data ? $url . '?' . http_build_query($data) : $url, $method === 'GET' ? [] : $data);
            $checked++;
            $body = (string) $response->getContent();

            if (str_contains($uri, '{') || $method !== 'GET' || str_contains($uri, 'availability')) {
                if ($response->getStatusCode() < 300 && !str_ends_with($uri, 'customers')) {
                    $leaks[] = "{$method} {$uri} → {$response->getStatusCode()}";
                }
            }

            foreach ($foreign as $id) {
                if ($method === 'GET' && $response->getStatusCode() < 300 && str_contains($body, $id)) {
                    $leaks[] = "{$method} {$uri} muestra {$id}";
                }
            }
        }
    }

    expect($checked)->toBeGreaterThanOrEqual(11)
        ->and($leaks)->toBe([])
        ->and(DB::table('appointments')->where('tenant_id', $other->tenant->id)->count())->toBe(1);
});
