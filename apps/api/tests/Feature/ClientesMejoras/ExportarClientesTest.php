<?php

use Illuminate\Support\Facades\DB;

function exportarClientes(): array
{
    return ['screen' => 'customers', 'title' => 'Clientes', 'format' => 'xlsx', 'scope' => 'filtered', 'rows' => 12];
}

it('descargar la lista de clientes exige su permiso propio', function () {
    $co = $this->company();
    $sinPermiso = $this->staff($co, 'manager');
    $rol = DB::table('roles')->where('tenant_id', $co->tenant->id)->where('slug', 'manager')->value('id');
    DB::table('role_permissions')->where('role_id', $rol)->whereIn('permission_id', DB::table('permissions')->where('name', 'customers.export')->select('id'))->delete();

    $this->as($sinPermiso)->postJson('/api/data-exports', exportarClientes())->assertForbidden();
    $this->as($sinPermiso)->postJson('/api/data-exports', ['screen' => 'sales'] + exportarClientes())->assertCreated();
});

it('cuando otra persona descarga los clientes, el dueño recibe un aviso', function () {
    $co = $this->company();
    $gerente = $this->staff($co, 'manager', null, 'Gerente Exporta');

    $this->as($gerente)->postJson('/api/data-exports', exportarClientes())->assertCreated();

    $aviso = DB::table('user_notifications')->where('user_id', $co->owner->id)->where('type', 'customers.exported')->first();

    expect($aviso)->not->toBeNull()
        ->and($aviso->body)->toContain('Gerente Exporta')
        ->and($aviso->body)->toContain('12 clientes');
});

it('si el dueño descarga, no se avisa a sí mismo', function () {
    $co = $this->company();

    $this->as($co->owner)->postJson('/api/data-exports', exportarClientes())->assertCreated();

    expect(DB::table('user_notifications')->where('type', 'customers.exported')->count())->toBe(0);
});
