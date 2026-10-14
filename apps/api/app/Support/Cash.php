<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cajas y turnos de caja. Cada sede activa tiene al menos una caja (se crea
 * sola). Un turno va de la apertura al cierre: lo que debía haber en efectivo
 * se calcula de sus movimientos, y al cerrar se compara con lo contado.
 */
class Cash
{
    public const DEFAULT_NAME = 'Caja principal';

    // Movimientos que alguien registra a mano, y si suman o restan.
    public const MANUAL = ['income' => 'Ingreso', 'expense' => 'Gasto', 'withdrawal' => 'Retiro'];
    public const SIGNS = ['sale' => 1, 'income' => 1, 'expense' => -1, 'withdrawal' => -1, 'refund' => -1];
    public const TYPE_LABELS = ['sale' => 'Cobro', 'refund' => 'Devolución'] + self::MANUAL;

    /**
     * Toda sede activa sin caja recibe la suya, salvo que la empresa haya
     * elegido crear sus cajas a mano. Nunca quita una caja que ya existe.
     */
    public static function ensureRegisters(Tenant $tenant): void
    {
        if (!AppointmentFlow::rule($tenant, 'cash.auto_register')) {
            return;
        }

        $branches = DB::table('branches')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->whereNotIn('id', DB::table('cash_registers')->where('tenant_id', $tenant->id)->select('branch_id'))
            ->pluck('id');

        foreach ($branches as $branchId) {
            DB::table('cash_registers')->insertOrIgnore([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'branch_id' => $branchId,
                'name' => self::DEFAULT_NAME, 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * Turnos abiertos de una sede, con el nombre de su caja.
     */
    public static function openSessions(string $tenantId, string $branchId): Collection
    {
        return DB::table('cash_sessions as s')
            ->join('cash_registers as r', 'r.id', '=', 's.cash_register_id')
            ->where('s.tenant_id', $tenantId)
            ->where('s.branch_id', $branchId)
            ->where('s.status', 'open')
            ->orderBy('s.opened_at')
            ->get(['s.id', 's.opened_by', 'r.name as register_name']);
    }

    /**
     * Un turno listo para mostrarse: sus totales, lo que debe haber en
     * efectivo y, si se pide, cada movimiento.
     *
     * @return array<string, mixed>
     */
    public static function present(object $session, bool $withMovements = false): array
    {
        $movements = DB::table('cash_movements')->where('cash_session_id', $session->id)->orderByDesc('created_at')->get();
        $sum = fn (string $type) => round((float) $movements->where('type', $type)->sum('amount'), 2);

        $expected = round((float) $session->opening_amount + $movements->sum(fn ($m) => (self::SIGNS[$m->type] ?? 0) * (float) $m->amount), 2);

        // Lo cobrado en la sede durante el turno por cada medio, para cuadrar también lo que no es efectivo.
        $byMethod = DB::table('payments')
            ->where('tenant_id', $session->tenant_id)
            ->where('branch_id', $session->branch_id)
            ->whereIn('status', ['confirmed', 'refunded'])
            ->where('created_at', '>=', $session->opened_at)
            ->when($session->closed_at, fn ($q) => $q->where('created_at', '<=', $session->closed_at))
            ->where(fn ($q) => $q->where('method', '!=', 'cash')->orWhere('cash_session_id', $session->id))
            ->groupBy('method')
            ->selectRaw('method, sum(amount) as total, count(*) as count')
            ->get()
            ->map(fn ($row) => ['method' => $row->method, 'label' => Sales::METHODS[$row->method] ?? $row->method, 'total' => round((float) $row->total, 2), 'count' => (int) $row->count])
            ->values();

        $names = DB::table('users')
            ->whereIn('id', array_filter([$session->opened_by, $session->closed_by, ...$movements->pluck('created_by')->all()]))
            ->get(['id', 'first_name', 'last_name'])
            ->mapWithKeys(fn ($u) => [$u->id => trim("{$u->first_name} {$u->last_name}")]);

        $iso = fn ($value) => $value ? Carbon::parse($value)->toIso8601String() : null;

        $data = [
            'id'              => $session->id,
            'register_id'     => $session->cash_register_id,
            'branch_id'       => $session->branch_id,
            'status'          => $session->status,
            'opened_at'       => $iso($session->opened_at),
            'closed_at'       => $iso($session->closed_at),
            'opened_by'       => $names[$session->opened_by] ?? null,
            'closed_by'       => $session->closed_by ? ($names[$session->closed_by] ?? null) : null,
            'opening_amount'  => round((float) $session->opening_amount, 2),
            'sales'           => $sum('sale'),
            'income'          => $sum('income'),
            'expense'         => $sum('expense'),
            'withdrawal'      => $sum('withdrawal'),
            'refund'          => $sum('refund'),
            // Abierto: lo que debe haber ahora. Cerrado: lo que debía haber al cerrar.
            'expected_amount' => $session->status === 'closed' && $session->expected_amount !== null ? round((float) $session->expected_amount, 2) : $expected,
            'closing_amount'  => $session->closing_amount !== null ? round((float) $session->closing_amount, 2) : null,
            'difference'      => $session->difference !== null ? round((float) $session->difference, 2) : null,
            'closing_notes'   => $session->closing_notes,
            'movement_count'  => $movements->count(),
            'by_method'       => $byMethod,
        ];

        if ($withMovements) {
            $data['movements'] = $movements->map(fn ($m) => [
                'id'          => $m->id,
                'type'        => $m->type,
                'type_label'  => self::TYPE_LABELS[$m->type] ?? $m->type,
                'sign'        => self::SIGNS[$m->type] ?? 1,
                'amount'      => round((float) $m->amount, 2),
                'description' => $m->description,
                'by'          => $m->created_by ? ($names[$m->created_by] ?? null) : null,
                'at'          => $iso($m->created_at),
            ])->values();
        }

        return $data;
    }
}
