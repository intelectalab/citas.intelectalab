<?php

use App\Support\DataExports;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

function hojaDelReporte(string $contenido, int $hoja): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'rep');
    file_put_contents($tmp, $contenido);
    $zip = new ZipArchive();
    $zip->open($tmp);
    $xml = (string) $zip->getFromName("xl/worksheets/sheet{$hoja}.xml");
    $zip->close();
    @unlink($tmp);

    return $xml;
}

beforeEach(function () {
    Storage::fake('local');
    $this->co = $this->company(['modules_off' => []]);
    $this->servicio = $this->servicePriced($this->co->tenant, 100, 'Corte reporte');
    $this->clienta = $this->customer($this->co->tenant, 'Lucía', 'Reporte');

    $this->cobrar = function () {
        $venta = $this->as($this->co->owner)->postJson('/api/pos/sales', [
            'branch_id' => $this->co->branch->id, 'customer_id' => $this->clienta->id,
            'items' => [['type' => 'service', 'id' => $this->servicio->id, 'quantity' => 1]],
        ])->assertCreated()->json('sale');
        $this->pay($this->co, $venta['id'], ['method' => 'cash', 'amount' => $venta['total']])->assertOk();
    };
});

it('un reporte chico se prepara al instante con enlace firmado que vence y queda en la auditoría', function () {
    $this->openCash($this->co);
    ($this->cobrar)();

    $res = $this->as($this->co->owner)->postJson('/api/reports/export', ['tab' => 'sales', 'period' => '30'])->assertCreated()->json();
    $row = DB::table('data_exports')->where('id', $res['export']['id'])->first();

    expect($res['queued'])->toBeFalse()
        ->and($res['export']['status'])->toBe('ready')
        ->and($res['export']['url'])->not->toBeNull()
        ->and($row->kind)->toBe('report')
        ->and($res['items'])->toHaveCount(1)
        ->and(DB::table('audit_events')->where('tenant_id', $this->co->tenant->id)->where('action', 'data.export')->where('entity_type', 'reports')->exists())->toBeTrue();

    $resumen = hojaDelReporte(Storage::disk('local')->get($row->file_path), 1);
    $porMedio = hojaDelReporte(Storage::disk('local')->get($row->file_path), 3);
    expect($resumen)->toContain('Ingresos')->toContain('<v>100</v>')
        ->and($porMedio)->toContain('Medio');

    $path = parse_url($res['export']['url'], PHP_URL_PATH) . '?' . parse_url($res['export']['url'], PHP_URL_QUERY);
    $descarga = $this->get($path)->assertOk();
    expect($descarga->headers->get('content-disposition'))->toContain('Reporte');
    $this->get(str_replace('signature=', 'signature=x', $path))->assertForbidden();

    $this->travel(DataExports::DAYS + 1)->days();
    $this->get($path)->assertForbidden();
});

it('un reporte grande va a la cola y avisa en la campana con el enlace', function () {
    config(['reports.queue_after' => 0]);
    $this->openCash($this->co);
    ($this->cobrar)();

    $res = $this->as($this->co->owner)->postJson('/api/reports/export', ['tab' => 'appointments', 'period' => '90'])->assertStatus(202)->json();

    expect($res['queued'])->toBeTrue()
        ->and($res['message'])->toContain('campana')
        ->and(DB::table('user_notifications')->where('user_id', $this->co->owner->id)->where('type', 'report.export_ready')->value('link'))->toBe('/reports#descargas');

    $lista = $this->as($this->co->owner)->getJson('/api/reports/exports')->assertOk()->json('items');
    expect($lista[0]['status'])->toBe('ready')->and($lista[0]['url'])->not->toBeNull();
});

it('no mezcla los reportes con el archivo de todos los datos del plan', function () {
    $this->as($this->co->owner)->postJson('/api/reports/export', ['tab' => 'sales'])->assertCreated();

    expect($this->as($this->co->owner)->getJson('/api/billing/exports')->json('items'))->toBe([]);
    $this->as($this->co->owner)->postJson('/api/billing/exports')->assertStatus(202);
    expect($this->as($this->co->owner)->getJson('/api/reports/exports')->json('items'))->toHaveCount(1);
});

it('sin permiso de exportar no se puede pedir y cada quien ve solo sus descargas', function () {
    $this->as($this->co->owner)->postJson('/api/reports/export', ['tab' => 'sales'])->assertCreated();
    $profesional = $this->staff($this->co, 'professional', $this->co->branch, 'Pía Sin Permiso');

    $this->as($profesional)->postJson('/api/reports/export', ['tab' => 'sales'])->assertForbidden();

    $otra = $this->company();
    expect($this->as($otra->owner)->getJson('/api/reports/exports')->json('items'))->toBe([]);
});

it('sin permiso de montos el archivo no trae los montos', function () {
    $this->openCash($this->co);
    ($this->cobrar)();
    $gerente = $this->staff($this->co, 'manager');
    DB::table('user_permissions')->insert([
        'tenant_id' => $this->co->tenant->id, 'user_id' => $gerente->id, 'granted' => false,
        'permission_id' => DB::table('permissions')->where('name', 'reports.money')->value('id'),
    ]);

    $id = $this->as($gerente)->postJson('/api/reports/export', ['tab' => 'sales'])->assertCreated()->json('export.id');
    $row = DB::table('data_exports')->where('id', $id)->first();
    $resumen = hojaDelReporte(Storage::disk('local')->get($row->file_path), 1);

    expect($row->status)->toBe('ready')
        ->and($resumen)->not->toContain('Ingresos')->toContain('Ventas')
        ->and(hojaDelReporte(Storage::disk('local')->get($row->file_path), 2))->toBe('');
});

it('una pestaña de Analítica avanzada sin el módulo no se exporta', function () {
    $basica = $this->company(['modules_off' => ['reports_adv']]);

    $this->as($basica->owner)->postJson('/api/reports/export', ['tab' => 'customers'])->assertForbidden();
});

it('cada pestaña arma su archivo con datos', function () {
    $this->openCash($this->co);
    ($this->cobrar)();

    foreach (['sales', 'appointments', 'customers', 'pros', 'catalog', 'cash', 'inventory'] as $tab) {
        $id = $this->as($this->co->owner)->postJson('/api/reports/export', ['tab' => $tab, 'period' => 'month'])->assertCreated()->json('export.id');
        expect(DB::table('data_exports')->where('id', $id)->value('status'))->toBe('ready', $tab);
    }
});
