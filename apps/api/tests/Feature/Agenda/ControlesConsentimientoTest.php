<?php

use App\Support\Messaging;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('el aviso del control no le llega a quien pidió no recibir correos', function () {
    $co = $this->company();
    DB::table('tenant_integrations')->insert([
        'id' => (string) Str::uuid(), 'tenant_id' => $co->tenant->id, 'kind' => 'email', 'provider' => 'simulated', 'mode' => 'simulated',
        'settings' => json_encode(['from_address' => 'citas@empresa.test', 'from_name' => 'Empresa']), 'status' => 'active',
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $acepta = $this->customer($co->tenant, 'Acepta', 'Correos');
    $no = $this->customer($co->tenant, 'No', 'Quiere');
    \App\Http\Controllers\Api\MessageController::saveConsents($co->tenant->id, $no->id, ['email' => false], 'staff');

    foreach ([$acepta, $no] as $cliente) {
        DB::table('recalls')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $co->tenant->id, 'customer_id' => $cliente->id,
            'label' => 'Control anual', 'due_date' => now('America/Lima')->addDay()->format('Y-m-d'), 'status' => 'pending',
        ]);
    }

    Messaging::generate($co->tenant, true);

    $avisos = DB::table('messages')->where('tenant_id', $co->tenant->id)->where('kind', 'recall_due')->pluck('customer_id')->all();

    expect($avisos)->toContain($acepta->id)->not->toContain($no->id);
});
