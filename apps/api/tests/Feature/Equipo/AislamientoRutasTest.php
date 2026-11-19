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
    'class'              => 'class_sessions',
    'enrollment'         => 'class_enrollments',
    'membership'         => 'memberships',
    'refundRequest'      => 'refund_requests',
];

const TABLE_BY_ROUTE = [
    'api/billing/invoices/{invoice}/receipt' => ['invoice' => 'subscription_invoices'],
    'api/billing/transfers/{id}/voucher'     => ['id' => 'plan_payments'],
    'api/support/tickets/{ticket}/files/{message}' => ['message' => 'saas_support_messages'],
];

const PLATFORM_PREFIXES = ['api/admin', 'api/tenants', 'api/saas-plans', 'api/tenant-contacts'];

const SKIPPED_PREFIXES = [...PLATFORM_PREFIXES, 'api/portal'];

const NOT_A_RECORD = [
    'variant' => 'Es la versión del logo (clara u oscura) de la propia empresa: no es un registro que se pueda pedir de otra.',
    'kind'    => 'Es el tipo de aviso (recordatorio, confirmación...) de la propia empresa: no es un registro que se pueda pedir de otra.',
    'method'  => 'Es el medio de pago (Yape, Plin...) cuyo QR se guarda en la propia empresa: no es un registro que se pueda pedir de otra.',
];

const JUSTIFIED_ROUTES = [
    'POST api/announcements/{announcement}/dismiss' => 'Los anuncios son de la plataforma y los ven todas las empresas; ocultarlo solo cambia la vista de quien lo cierra.',
];

function otherTenantId(string $table, string $tenantId): ?string
{
    if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'tenant_id')) {
        return null;
    }

    return DB::table($table)->where('tenant_id', $tenantId)->value('id') ?? fillRow($table, $tenantId);
}

function foreignTable(string $table, string $column): ?string
{
    return DB::selectOne(
        "select ccu.table_name from information_schema.table_constraints tc
            join information_schema.key_column_usage kcu on kcu.constraint_name = tc.constraint_name and kcu.table_schema = tc.table_schema
            join information_schema.constraint_column_usage ccu on ccu.constraint_name = tc.constraint_name and ccu.table_schema = tc.table_schema
            where tc.constraint_type = 'FOREIGN KEY' and tc.table_schema = 'public' and tc.table_name = ? and kcu.column_name = ?
            limit 1",
        [$table, $column],
    )?->table_name;
}

function allowedValues(string $table): array
{
    $values = [];
    $checks = DB::select("select pg_get_constraintdef(c.oid) as def from pg_constraint c join pg_class t on t.oid = c.conrelid where c.contype = 'c' and t.relname = ?", [$table]);

    foreach ($checks as $check) {
        if (preg_match("/\(+(\w+)\)?(?:::text)? = ANY \(+ARRAY\[\(?'([^']*)'/", $check->def, $m)) {
            $values[$m[1]] ??= $m[2];
        }
    }

    return $values;
}

function relatedId(string $table, string $column, string $tenantId, int $depth): ?string
{
    $foreign = foreignTable($table, $column);

    if (!$foreign) {
        return null;
    }

    $tenanted = Schema::hasColumn($foreign, 'tenant_id');
    $id = DB::table($foreign)->when($tenanted, fn ($q) => $q->where('tenant_id', $tenantId))->value('id');

    if ($id || !$tenanted || $depth >= 3) {
        return $id;
    }

    return fillRow($foreign, $tenantId, $depth + 1);
}

function fillRow(string $table, string $tenantId, int $depth = 0, array $overrides = []): ?string
{
    $columns = DB::select('select column_name, udt_name, is_nullable, column_default, character_maximum_length from information_schema.columns where table_schema = ? and table_name = ? order by ordinal_position', ['public', $table]);
    $allowed = allowedValues($table);
    $minutes = 0;
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

        if (array_key_exists($name, $overrides)) {
            $row[$name] = $overrides[$name];
            continue;
        }

        if ($column->is_nullable === 'YES' || $column->column_default !== null) {
            continue;
        }

        $text = Str::upper(Str::random(8));

        $row[$name] = match (true) {
            isset($allowed[$name])                                       => $allowed[$name],
            $column->udt_name === 'uuid'                                 => relatedId($table, $name, $tenantId, $depth) ?? (string) Str::uuid(),
            in_array($column->udt_name, ['int2', 'int4', 'int8', 'numeric', 'float8'], true) => 1,
            $column->udt_name === 'bool'                                 => false,
            $column->udt_name === 'date'                                 => now()->toDateString(),
            str_starts_with($column->udt_name, 'timestamp')              => now()->addMinutes(30 * $minutes++),
            in_array($column->udt_name, ['json', 'jsonb'], true)         => '{}',
            str_starts_with($column->udt_name, '_')                      => '{}',
            default                                                      => $column->character_maximum_length ? substr($text, 0, (int) $column->character_maximum_length) : $text,
        };
    }

    try {
        DB::transaction(fn () => DB::table($table)->insert($row));

        return $row['id'];
    } catch (\Throwable $e) {
        $GLOBALS['fillRowErrors'][$table] = mb_substr($e->getMessage(), 0, 160);

        return null;
    }
}

function otherTenantExtras($test, object $co, object $servicio, object $pro, object $clienta): array
{
    $tenantId = $co->tenant->id;
    $class = (string) Str::uuid();
    $quote = (string) Str::uuid();

    DB::table('class_sessions')->insert([
        'id' => $class, 'tenant_id' => $tenantId, 'branch_id' => $co->branch->id, 'service_id' => $servicio->id, 'professional_id' => $pro->id,
        'title' => 'Clase ajena', 'capacity' => 5, 'starts_at' => now()->addDays(2), 'ends_at' => now()->addDays(2)->addHour(),
    ]);
    DB::table('class_enrollments')->insert(['tenant_id' => $tenantId, 'class_session_id' => $class, 'customer_id' => $clienta->id]);
    DB::table('quotes')->insert([
        'id' => $quote, 'tenant_id' => $tenantId, 'branch_id' => $co->branch->id, 'customer_id' => $clienta->id, 'code' => 'PRE-' . Str::upper(Str::random(6)),
        'title' => 'Presupuesto ajeno', 'status' => 'sent', 'valid_until' => now()->addDays(10)->toDateString(), 'total' => 50,
    ]);

    $sede2 = fillRow('branches', $tenantId, 0, ['company_id' => $co->branch->company_id, 'name' => 'Sede ajena dos', 'status' => 'active']);
    $almacenes = [fillRow('warehouses', $tenantId, 0, ['branch_id' => $co->branch->id]), fillRow('warehouses', $tenantId, 0, ['branch_id' => $sede2])];
    fillRow('stock_transfers', $tenantId, 0, ['from_warehouse_id' => $almacenes[0], 'to_warehouse_id' => $almacenes[1]]);
    fillRow('commission_rules', $tenantId, 0, ['kind' => 'service', 'service_id' => $servicio->id]);
    fillRow('calendar_feeds', $tenantId, 0, ['branch_id' => $co->branch->id]);

    $subscription = DB::table('subscriptions')->where('tenant_id', $tenantId)->first();

    if ($subscription) {
        DB::table('plan_payments')->insert([
            'tenant_id' => $tenantId, 'subscription_id' => $subscription->id, 'plan_id' => $subscription->plan_id, 'billing_cycle' => 'monthly',
            'kind' => 'transfer', 'status' => 'pending', 'amount' => 99, 'currency' => 'PEN', 'provider' => 'transfer',
        ]);
    }

    return ['class' => $class, 'quote' => $quote];
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
    otherTenantExtras($this, $other, $servicio, $pro, $clienta);
    $device = (string) Str::uuid();
    DB::table('account_tokens')->insert(['id' => $device, 'kind' => 'trusted_device', 'account_type' => 'user', 'account_id' => $other->owner->id, 'token_hash' => Str::random(64), 'expires_at' => now()->addMonth()]);
    $byRoute = ['api/account/devices/{device}' => ['device' => $device]];

    $leaks = [];
    $checked = 0;
    $uncovered = [];
    $justified = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        if (!str_starts_with($uri, 'api/') || !str_contains($uri, '{') || collect(SKIPPED_PREFIXES)->contains(fn ($p) => str_starts_with($uri, $p))) {
            continue;
        }

        if (!collect($route->gatherMiddleware())->contains(fn ($m) => is_string($m) && str_contains($m, 'auth:sanctum'))) {
            continue;
        }

        $key = implode('|', array_diff($route->methods(), ['HEAD'])) . ' ' . $uri;

        if (isset(JUSTIFIED_ROUTES[$key])) {
            $justified[] = $key;
            continue;
        }

        $params = $route->parameterNames();
        $values = [];

        foreach ($params as $param) {
            if (isset(NOT_A_RECORD[$param])) {
                continue 2;
            }

            $table = TABLE_BY_ROUTE[$uri][$param] ?? TABLE_BY_PARAM[$param] ?? null;
            $id = $byRoute[$uri][$param] ?? ($table ? otherTenantId($table, $other->tenant->id) : null);

            if (!$id) {
                $uncovered[] = "{$key} ({$param}) " . ($GLOBALS['fillRowErrors'][$table] ?? '');
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
        ->and($errors ?? [])->toBe([])
        ->and($uncovered)->toBe([])
        ->and($justified)->toEqualCanonicalizing(array_keys(JUSTIFIED_ROUTES));
});

it('ninguna ruta del portal deja ver, cambiar ni cancelar registros de otra empresa', function () {
    $mine = $this->company(['modules_off' => []]);
    $other = $this->company(['modules_off' => []]);
    $clientaMia = $this->customer($mine->tenant, 'Clienta', 'Mía');

    $servicio = $this->servicePriced($other->tenant, 50, 'Servicio ajeno');
    DB::table('services')->where('id', $servicio->id)->update(['duration_minutes' => 30]);
    $pro = $this->professional($other, 'Pro Ajena');
    $clienta = $this->customer($other->tenant, 'Clienta', 'Ajena');
    $cita = $this->book($other, $clienta, $servicio, $pro, $this->at($other->tenant, '10:00'))->assertCreated()->json('id');
    $this->openCash($other);
    $venta = $this->sell($other, [['type' => 'service', 'id' => $servicio->id, 'quantity' => 1]], ['customer_id' => $clienta->id]);
    $this->pay($other, $venta['id'], ['method' => 'cash', 'amount' => $venta['total']])->assertOk();
    $extras = otherTenantExtras($this, $other, $servicio, $pro, $clienta);

    $tables = ['appointment' => 'appointments', 'class' => 'class_sessions', 'link' => 'payment_links', 'sale' => 'sales', 'quote' => 'quotes', 'plan' => 'package_plans', 'entry' => 'waitlist_entries'];
    $foreign = [$cita, $venta['id'], $clienta->id, $servicio->id, $extras['quote'], $extras['class']];
    $leaks = [];
    $uncovered = [];
    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        if (!str_starts_with($uri, 'api/portal/')) {
            continue;
        }

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            $values = [];

            foreach ($route->parameterNames() as $param) {
                $id = isset($tables[$param]) ? otherTenantId($tables[$param], $other->tenant->id) : null;

                if (!$id) {
                    $uncovered[] = "{$method} {$uri} ({$param})";
                    continue 3;
                }

                $values[$param] = $id;
            }

            if (!$values && $method !== 'GET') {
                continue;
            }

            $url = '/' . preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => $values[$m[1]], $uri);
            $this->as($clientaMia);
            $response = $this->json($method, $url, []);
            $checked++;
            $body = (string) $response->getContent();

            if ($response->getStatusCode() >= 500) {
                $leaks[] = "{$method} {$uri} → {$response->getStatusCode()}";
            }

            if ($values && $response->getStatusCode() < 300) {
                $leaks[] = "{$method} {$uri} → {$response->getStatusCode()}";
            }

            foreach ($foreign as $id) {
                if ($response->getStatusCode() < 300 && str_contains($body, $id)) {
                    $leaks[] = "{$method} {$uri} muestra {$id}";
                }
            }
        }
    }

    expect($checked)->toBeGreaterThan(20)
        ->and($uncovered)->toBe([])
        ->and($leaks)->toBe([])
        ->and(DB::table('appointments')->where('id', $cita)->value('status'))->not->toBe('cancelled')
        ->and(DB::table('quotes')->where('id', $extras['quote'])->value('status'))->toBe('sent');
});

it('el portal tampoco deja tocar citas, compras ni presupuestos de otra clienta de la misma empresa', function () {
    $co = $this->company(['modules_off' => []]);
    $yo = $this->customer($co->tenant, 'Clienta', 'Yo');
    $vecina = $this->customer($co->tenant, 'Clienta', 'Vecina');
    $servicio = $this->servicePriced($co->tenant, 50, 'Servicio');
    DB::table('services')->where('id', $servicio->id)->update(['duration_minutes' => 30]);
    $pro = $this->professional($co, 'Pro');
    $cita = $this->book($co, $vecina, $servicio, $pro, $this->at($co->tenant, '10:00'))->assertCreated()->json('id');
    $this->openCash($co);
    $venta = $this->sell($co, [['type' => 'service', 'id' => $servicio->id, 'quantity' => 1]], ['customer_id' => $vecina->id]);
    $quote = otherTenantExtras($this, $co, $servicio, $pro, $vecina)['quote'];

    $this->as($yo)->postJson("/api/portal/appointments/{$cita}/cancel")->assertNotFound();
    $this->as($yo)->postJson("/api/portal/appointments/{$cita}/confirm")->assertNotFound();
    $this->as($yo)->postJson("/api/portal/appointments/{$cita}/reschedule", ['starts_at' => $this->at($co->tenant, '12:00')])->assertNotFound();
    $this->as($yo)->getJson("/api/portal/purchases/{$venta['id']}")->assertNotFound();
    $this->as($yo)->postJson("/api/portal/quotes/{$quote}/accept")->assertNotFound();
    $this->as($yo)->getJson("/api/portal/quotes/{$quote}/pdf")->assertNotFound();

    expect(DB::table('appointments')->where('id', $cita)->value('status'))->not->toBe('cancelled')
        ->and(DB::table('quotes')->where('id', $quote)->value('status'))->toBe('sent');
});

it('las rutas de la plataforma no responden a usuarios de una empresa ni a clientas del portal', function () {
    $co = $this->company();
    $clienta = $this->customer($co->tenant);
    $values = ['code' => 'PEN', 'action' => 'remind', 'tenant_contact' => fillRow('tenant_contacts', $co->tenant->id), 'tenant' => $co->tenant->id, 'user' => $co->owner->id];
    $open = [];
    $checked = 0;

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        if (!collect(PLATFORM_PREFIXES)->contains(fn ($p) => str_starts_with($uri, $p))) {
            continue;
        }

        $url = '/' . preg_replace_callback('/\{(\w+)\??\}/', fn ($m) => $values[$m[1]] ?? (string) Str::uuid(), $uri);

        foreach (array_diff($route->methods(), ['HEAD']) as $method) {
            foreach (['dueña' => $co->owner, 'clienta' => $clienta] as $who => $account) {
                $this->as($account);
                $status = $this->json($method, $url, [])->getStatusCode();
                $checked++;

                if (!in_array($status, [401, 403], true)) {
                    $open[] = "{$method} {$uri} ({$who}) → {$status}";
                }
            }
        }
    }

    expect($checked)->toBeGreaterThan(100)->and($open)->toBe([]);
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
