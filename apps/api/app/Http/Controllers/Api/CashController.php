<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Cash;
use App\Support\Notifier;
use App\Support\Sales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Caja: abrir un turno, registrar ingresos, gastos y retiros, y cerrarlo con
 * su arqueo. La opera el personal que no es solo profesional; crear o cambiar
 * cajas es del administrador de la empresa.
 */
class CashController extends Controller
{
    private function tenant(Request $request): Tenant
    {
        $user = $request->user();
        abort_unless($user->tenant, 403);
        abort_unless(Sales::ready(), 503, Sales::NOT_READY);
        abort_if($this->isOnlyProfessional($user), 403, 'La caja la opera recepción o la administración de la empresa.');

        return $user->tenant->loadMissing(['settings', 'activeSubscription.plan']);
    }

    private function isOnlyProfessional(User $user): bool
    {
        return !$user->isTenantAdmin() && $user->roles()->where('slug', 'professional')->exists();
    }

    private function session(Tenant $tenant, string $id, bool $lock = false): object
    {
        $query = DB::table('cash_sessions')->where('tenant_id', $tenant->id)->where('id', $id);
        $session = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($session, 404);

        return $session;
    }

    /**
     * Las cajas de la empresa con su turno abierto, y los turnos ya cerrados.
     */
    public function index(Request $request)
    {
        $tenant = $this->tenant($request);
        Cash::ensureRegisters($tenant);

        $branches = DB::table('branches')->where('tenant_id', $tenant->id)->orderBy('name')->get(['id', 'name', 'status']);
        $branchNames = $branches->pluck('name', 'id');
        $registers = DB::table('cash_registers')->where('tenant_id', $tenant->id)->orderBy('name')->get();
        $registerNames = $registers->pluck('name', 'id');
        $open = DB::table('cash_sessions')->where('tenant_id', $tenant->id)->where('status', 'open')->get()->keyBy('cash_register_id');

        $closed = DB::table('cash_sessions')
            ->where('tenant_id', $tenant->id)
            ->where('status', 'closed')
            ->orderByDesc('closed_at')
            ->limit(300)
            ->get();

        $decorate = fn (array $session) => $session + [
            'register_name' => $registerNames[$session['register_id']] ?? null,
            'branch_name'   => $branchNames[$session['branch_id']] ?? null,
        ];

        return response()->json([
            'can_manage' => $request->user()->isTenantAdmin(),
            'tolerance'  => Sales::rules($tenant)['cash_tolerance'],
            'branches'   => $branches->where('status', 'active')->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->values(),
            'registers'  => $registers->map(fn ($r) => [
                'id'          => $r->id,
                'name'        => $r->name,
                'status'      => $r->status,
                'branch_id'   => $r->branch_id,
                'branch_name' => $branchNames[$r->branch_id] ?? null,
                'session'     => isset($open[$r->id]) ? Cash::present($open[$r->id]) : null,
            ])->values(),
            'sessions'   => $closed->map(fn ($s) => $decorate(Cash::present($s)))->values(),
        ]);
    }

    public function show(Request $request, string $session)
    {
        $tenant = $this->tenant($request);
        $session = $this->session($tenant, $session);
        $register = DB::table('cash_registers')->where('id', $session->cash_register_id)->first();

        return response()->json(Cash::present($session, true) + [
            'register_name' => $register->name ?? null,
            'branch_name'   => DB::table('branches')->where('id', $session->branch_id)->value('name'),
            'tolerance'     => Sales::rules($tenant)['cash_tolerance'],
        ]);
    }

    public function storeRegister(Request $request)
    {
        $tenant = $this->tenant($request);
        abort_unless($request->user()->isTenantAdmin(), 403, 'Solo el administrador de la empresa agrega cajas.');

        $validated = $request->validate([
            'branch_id' => ['required', 'uuid', Rule::exists('branches', 'id')->where('tenant_id', $tenant->id)],
            'name'      => ['required', 'string', 'max:100', Rule::unique('cash_registers', 'name')->where('branch_id', $request->input('branch_id'))],
        ], [
            'branch_id.required' => 'Elige la sede de la caja.',
            'name.required'      => 'Escribe el nombre de la caja.',
            'name.unique'        => 'Esa sede ya tiene una caja con ese nombre.',
        ]);

        $id = (string) Str::uuid();
        DB::table('cash_registers')->insert([
            'id' => $id, 'tenant_id' => $tenant->id, 'branch_id' => $validated['branch_id'], 'name' => trim($validated['name']),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['id' => $id], 201);
    }

    public function updateRegister(Request $request, string $register)
    {
        $tenant = $this->tenant($request);
        abort_unless($request->user()->isTenantAdmin(), 403, 'Solo el administrador de la empresa cambia las cajas.');

        $row = DB::table('cash_registers')->where('tenant_id', $tenant->id)->where('id', $register)->first();
        abort_unless($row, 404);

        $validated = $request->validate([
            'name'   => ['required', 'string', 'max:100', Rule::unique('cash_registers', 'name')->where('branch_id', $row->branch_id)->ignore($row->id)],
            'status' => ['required', Rule::in(['active', 'inactive'])],
        ], [
            'name.required' => 'Escribe el nombre de la caja.',
            'name.unique'   => 'Esa sede ya tiene una caja con ese nombre.',
        ]);

        if ($validated['status'] === 'inactive' && DB::table('cash_sessions')->where('cash_register_id', $row->id)->where('status', 'open')->exists()) {
            throw ValidationException::withMessages(['status' => 'Esta caja tiene un turno abierto: ciérralo antes de desactivarla.']);
        }

        DB::table('cash_registers')->where('id', $row->id)->update(['name' => trim($validated['name']), 'status' => $validated['status'], 'updated_at' => now()]);

        return response()->json(['id' => $row->id]);
    }

    public function open(Request $request, string $register)
    {
        $tenant = $this->tenant($request);

        $validated = $request->validate(['opening_amount' => 'required|numeric|min:0|max:9999999'], [
            'opening_amount.required' => 'Escribe con cuánto efectivo se abre la caja (puede ser 0).',
            'opening_amount.numeric'  => 'Escribe el monto como un número.',
        ]);

        return DB::transaction(function () use ($tenant, $register, $validated, $request) {
            $row = DB::table('cash_registers')->where('tenant_id', $tenant->id)->where('id', $register)->lockForUpdate()->first();
            abort_unless($row, 404);
            abort_if($row->status !== 'active', 422, 'Esta caja está desactivada.');
            abort_if(DB::table('cash_sessions')->where('cash_register_id', $row->id)->where('status', 'open')->exists(), 409, 'Esta caja ya está abierta.');

            $id = (string) Str::uuid();
            DB::table('cash_sessions')->insert([
                'id' => $id, 'tenant_id' => $tenant->id, 'branch_id' => $row->branch_id, 'cash_register_id' => $row->id,
                'status' => 'open', 'opened_by' => $request->user()->id, 'opening_amount' => round((float) $validated['opening_amount'], 2), 'opened_at' => now(),
            ]);

            return response()->json(Cash::present($this->session($tenant, $id)), 201);
        });
    }

    public function move(Request $request, string $session)
    {
        $tenant = $this->tenant($request);

        $validated = $request->validate([
            'type'        => ['required', Rule::in(array_keys(Cash::MANUAL))],
            'amount'      => 'required|numeric|min:0.01|max:9999999',
            'description' => 'required|string|max:255',
        ], [
            'type.required'        => 'Elige si es un ingreso, un gasto o un retiro.',
            'amount.required'      => 'Escribe el monto.',
            'amount.numeric'       => 'Escribe el monto como un número.',
            'amount.min'           => 'El monto debe ser mayor que cero.',
            'description.required' => 'Escribe el motivo.',
        ]);

        return DB::transaction(function () use ($tenant, $session, $validated, $request) {
            $row = $this->session($tenant, $session, true);
            abort_if($row->status !== 'open', 409, 'Este turno de caja ya está cerrado.');

            $amount = round((float) $validated['amount'], 2);

            // No puede salir más efectivo del que hay.
            if (Cash::SIGNS[$validated['type']] < 0 && $amount > Cash::present($row)['expected_amount']) {
                throw ValidationException::withMessages(['amount' => 'En la caja no hay tanto efectivo.']);
            }

            DB::table('cash_movements')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'cash_session_id' => $row->id,
                'type' => $validated['type'], 'amount' => $amount, 'description' => trim($validated['description']),
                'created_by' => $request->user()->id, 'created_at' => now(),
            ]);

            return response()->json(Cash::present($row, true), 201);
        });
    }

    /**
     * Cierre con arqueo: se compara lo contado con lo que debía haber. Una
     * diferencia mayor que la tolerancia de la empresa exige explicarla, y la
     * administración se entera.
     */
    public function close(Request $request, string $session)
    {
        $tenant = $this->tenant($request);

        $validated = $request->validate([
            'counted_amount' => 'required|numeric|min:0|max:9999999',
            'notes'          => 'nullable|string|max:500',
        ], [
            'counted_amount.required' => 'Escribe cuánto efectivo contaste.',
            'counted_amount.numeric'  => 'Escribe el monto como un número.',
        ]);

        return DB::transaction(function () use ($tenant, $session, $validated, $request) {
            $row = $this->session($tenant, $session, true);
            abort_if($row->status !== 'open', 409, 'Este turno de caja ya está cerrado.');

            $expected = Cash::present($row)['expected_amount'];
            $counted = round((float) $validated['counted_amount'], 2);
            $difference = round($counted - $expected, 2);
            $notes = trim((string) ($validated['notes'] ?? '')) ?: null;
            $tolerance = Sales::rules($tenant)['cash_tolerance'];
            $overTolerance = abs($difference) > $tolerance + 0.001;

            if ($overTolerance && !$notes) {
                throw ValidationException::withMessages([
                    'notes' => 'Hay una diferencia de S/ ' . number_format(abs($difference), 2) . ($difference < 0 ? ' (falta)' : ' (sobra)') . ': explica a qué se debe para cerrar.',
                ]);
            }

            DB::table('cash_sessions')->where('id', $row->id)->update([
                'status' => 'closed', 'closed_by' => $request->user()->id, 'closed_at' => now(),
                'expected_amount' => $expected, 'closing_amount' => $counted, 'difference' => $difference, 'closing_notes' => $notes,
            ]);

            if ($overTolerance) {
                $this->notifyDifference($tenant, $row, $difference, $request->user());
            }

            return response()->json(Cash::present($this->session($tenant, $row->id), true));
        });
    }

    private function notifyDifference(Tenant $tenant, object $session, float $difference, User $actor): void
    {
        try {
            if (!Notifier::available()) {
                return;
            }

            $register = DB::table('cash_registers')->where('id', $session->cash_register_id)->value('name');
            $branch = DB::table('branches')->where('id', $session->branch_id)->value('name');
            $who = trim("{$actor->first_name} {$actor->last_name}");
            $body = ($difference < 0 ? 'Faltan' : 'Sobran') . ' S/ ' . number_format(abs($difference), 2) . " en {$register}. La cerró {$who}.";

            foreach (Notifier::adminIds($tenant->id) as $userId) {
                // Quien cerró ya lo sabe.
                if ($userId !== $actor->id) {
                    Notifier::send($tenant->id, $userId, 'cash.difference', 'Cierre de caja con diferencia', implode(' · ', array_filter([$body, $branch])), '/cash');
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
