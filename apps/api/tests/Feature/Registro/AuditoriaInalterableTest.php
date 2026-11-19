<?php

use App\Models\AuditEvent;
use App\Support\CustomerRecords;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

function auditoriaEvento(string $tenantId, array $extra = []): AuditEvent
{
    return AuditEvent::create($extra + [
        'tenant_id'   => $tenantId,
        'action'      => 'prueba.registrada',
        'entity_type' => 'tenant',
        'entity_id'   => $tenantId,
        'actor_type'  => 'system',
        'after_data'  => ['valor' => 1],
    ]);
}

function auditoriaIntento(callable $fn): ?string
{
    try {
        DB::transaction($fn);
    } catch (QueryException $e) {
        return $e->getMessage();
    }

    return null;
}

beforeEach(function () {
    $this->co = $this->company();
    $this->evento = auditoriaEvento($this->co->tenant->id);
});

describe('La base no deja cambiar la auditoría', function () {
    it('rechaza modificar un registro', function () {
        $error = auditoriaIntento(fn () => DB::table('audit_events')->where('id', $this->evento->id)->update(['action' => 'otra.cosa']));

        expect($error)->toContain('no se pueden modificar ni borrar')
            ->and(DB::table('audit_events')->where('id', $this->evento->id)->value('action'))->toBe('prueba.registrada');
    });

    it('rechaza borrar un registro', function () {
        $error = auditoriaIntento(fn () => DB::table('audit_events')->where('id', $this->evento->id)->delete());

        expect($error)->toContain('no se pueden modificar ni borrar')
            ->and(DB::table('audit_events')->where('id', $this->evento->id)->exists())->toBeTrue();
    });

    it('rechaza vaciar la tabla', function () {
        $error = auditoriaIntento(fn () => DB::statement('truncate audit_events'));

        expect($error)->toContain('no se pueden modificar ni borrar')
            ->and(DB::table('audit_events')->count())->toBeGreaterThan(0);
    });

    it('sigue permitiendo agregar registros nuevos', function () {
        $antes = DB::table('audit_events')->count();

        auditoriaEvento($this->co->tenant->id, ['action' => 'prueba.otra']);

        expect(DB::table('audit_events')->count())->toBe($antes + 1);
    });
});

describe('El modelo tampoco lo permite', function () {
    it('no deja guardar cambios en un registro', function () {
        expect(fn () => $this->evento->update(['action' => 'otra.cosa']))->toThrow(LogicException::class, AuditEvent::LOCKED_MESSAGE);
    });

    it('no deja borrar un registro', function () {
        expect(fn () => $this->evento->delete())->toThrow(LogicException::class, AuditEvent::LOCKED_MESSAGE);
    });

    it('no deja cambios ni borrados masivos', function () {
        expect(fn () => AuditEvent::where('tenant_id', $this->co->tenant->id)->update(['action' => 'x']))->toThrow(LogicException::class)
            ->and(fn () => AuditEvent::where('tenant_id', $this->co->tenant->id)->delete())->toThrow(LogicException::class);

        expect(AuditEvent::where('id', $this->evento->id)->value('action'))->toBe('prueba.registrada');
    });
});

describe('Lo que antes tocaba la auditoría', function () {
    it('unir dos fichas de clientes no reescribe la auditoría de la ficha unida', function () {
        $queda = $this->customer($this->co->tenant, 'Rosa', 'Principal');
        $duplicada = $this->customer($this->co->tenant, 'Rosa', 'Repetida');
        $evento = auditoriaEvento($this->co->tenant->id, ['actor_type' => 'customer', 'actor_customer_id' => $duplicada->id, 'action' => 'portal.login']);

        $resultado = CustomerRecords::merge($queda, $duplicada);

        expect($resultado['moved'])->not->toHaveKey('audit_events')
            ->and(DB::table('audit_events')->where('id', $evento->id)->value('actor_customer_id'))->toBe($duplicada->id);
    });
});
