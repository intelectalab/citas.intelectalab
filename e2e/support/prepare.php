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
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
