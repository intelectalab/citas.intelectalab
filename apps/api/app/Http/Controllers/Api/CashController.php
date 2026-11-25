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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\Access;
use App\Support\Audit;
use App\Support\Currency;

class CashController extends Controller
{
    private function tenant(Request $request): Tenant
    {
        $user = $request->user();
        abort_unless($user->tenant, 403);
        abort_unless(Sales::ready(), 503, Sales::NOT_READY);

        return $user->tenant->loadMissing(['settings', 'activeSubscription.plan']);
    }

    private function isOnlyProfessional(User $user): bool
    {
        return !$user->isTenantAdmin() && $user->roles()->where('slug', 'professional')->exists();
    }

    private function assertOwn(User $user, object $session): void
    {
        abort_unless($user->allows('cash.others') || $session->opened_by === $user->id || (($session->review_status ?? null) && $user->allows('cash.approve')), 403, 'Este turno lo abrió otra persona: solo ella o quien tenga el permiso "Turnos de otros" puede verlo o manejarlo.');
    }

    private function session(Tenant $tenant, string $id, bool $lock = false): object
    {
        $query = DB::table('cash_sessions')->where('tenant_id', $tenant->id)->where('id', $id);
        $session = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($session, 404);

        return $session;
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);
        Cash::ensureRegisters($tenant);

        $limit = Access::branchLimit($request->user());
        $branches = DB::table('branches')->where('tenant_id', $tenant->id)->when($limit, fn ($q, $id) => $q->where('id', $id))->orderBy('name')->get(['id', 'name', 'status']);
        $branchNames = $branches->pluck('name', 'id');
        $registers = DB::table('cash_registers')->where('tenant_id', $tenant->id)->when($limit, fn ($q, $id) => $q->where('branch_id', $id))->orderBy('name')->get();
        $registerNames = $registers->pluck('name', 'id');
        $open = DB::table('cash_sessions')->where('tenant_id', $tenant->id)->where('status', 'open')->get()->keyBy('cash_register_id');

        $user = $request->user();
        $others = $user->allows('cash.others');
        $closed = DB::table('cash_sessions')
            ->where('tenant_id', $tenant->id)
            ->when($limit, fn ($q, $id) => $q->where('branch_id', $id))
            ->when(!$others, fn ($q) => $q->where(fn ($q) => $q->where('opened_by', $user->id)->when(Cash::reviewReady() && $user->allows('cash.approve'), fn ($q) => $q->orWhereNotNull('review_status'))))
            ->where('status', 'closed')
            ->orderByDesc('closed_at')
            ->limit(300)
            ->get();

        $decorate = fn (array $session) => $session + [
            'register_name' => $registerNames[$session['register_id']] ?? null,
            'branch_name'   => $branchNames[$session['branch_id']] ?? null,
        ];

        return response()->json([
            'can_manage' => $request->user()->allows('cash.create'),
            'can_operate' => $request->user()->allows('cash.edit'),
            'sees_others' => $others,
            'tolerance'  => Sales::rules($tenant)['cash_tolerance'],
            'review_over' => Sales::rules($tenant)['cash_review_over'],
            'review_ready' => Cash::reviewReady(),
            'can_approve' => $user->allows('cash.approve'),
            'branches'   => $branches->where('status', 'active')->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->values(),
            'registers'  => $registers->map(fn ($r) => [
                'id'          => $r->id,
                'name'        => $r->name,
                'status'      => $r->status,
                'branch_id'   => $r->branch_id,
                'branch_name' => $branchNames[$r->branch_id] ?? null,
                'session'     => !isset($open[$r->id]) ? null : ($others || $open[$r->id]->opened_by === $user->id
                    ? Cash::present($open[$r->id]) + ['mine' => $open[$r->id]->opened_by === $user->id]
                    : ['id' => $open[$r->id]->id, 'mine' => false, 'hidden' => true, 'opened_at' => \Illuminate\Support\Carbon::parse($open[$r->id]->opened_at)->toIso8601String(), 'opened_by' => \App\Support\Person::name(DB::table('users')->where('id', $open[$r->id]->opened_by)->first(['first_name', 'last_name']))]),
            ])->values(),
            'sessions'   => $closed->map(fn ($s) => $decorate(Cash::present($s)))->values(),
            'online'     => $this->onlineToday($tenant, $branches->where('status', 'active'), $limit),
        ]);
    }

    private function onlineToday(Tenant $tenant, $branches, ?string $limit): ?array
    {
        $from = now($tenant->zone())->startOfDay();
        $totals = \App\Support\OnlineCash::byBranch($tenant, $limit, $from, $from->copy()->addDay());

        if (!\App\Support\PaymentLinks::ready() || (!\App\Support\PaymentLinks::available($tenant) && !$totals)) {
            return null;
        }

        return [
            'date'     => $from->format('Y-m-d'),
            'currency' => Currency::of($tenant),
            'branches' => $branches->map(fn ($b) => ['branch_id' => $b->id, 'branch_name' => $b->name] + ($totals[$b->id] ?? ['received' => 0.0, 'refunded' => 0.0, 'net' => 0.0, 'count' => 0]))->values(),
        ];
    }

    public function online(Request $request)
    {
        $tenant = $this->tenant($request);
        $validated = $request->validate([
            'date'      => 'nullable|date_format:Y-m-d',
            'branch_id' => 'nullable|uuid',
        ]);

        $limit = Access::branchLimit($request->user());
        $branchId = $limit ?: ($validated['branch_id'] ?? null);

        if ($branchId) {
            abort_unless(DB::table('branches')->where('tenant_id', $tenant->id)->where('id', $branchId)->exists(), 404);
        }

        return response()->json(\App\Support\OnlineCash::day($tenant, $branchId, $validated['date'] ?? now($tenant->zone())->format('Y-m-d')) + [
            'branch_id' => $branchId,
        ]);
    }

    public function show(Request $request, string $session)
    {
        $tenant = $this->tenant($request);
        $session = $this->session($tenant, $session);
        Access::assertBranch($request->user(), $session->branch_id);
        $this->assertOwn($request->user(), $session);
        $register = DB::table('cash_registers')->where('id', $session->cash_register_id)->first();

        return response()->json(Cash::present($session, true) + [
            'register_name' => $register->name ?? null,
            'branch_name'   => DB::table('branches')->where('id', $session->branch_id)->value('name'),
            'tolerance'     => Sales::rules($tenant)['cash_tolerance'],
            'can_review'    => ($session->review_status ?? null) === 'pending' && $request->user()->allows('cash.approve')
                && ($session->closed_by !== $request->user()->id || $request->user()->isTenantAdmin()),
        ]);
    }

    public function storeRegister(Request $request)
    {
        $tenant = $this->tenant($request);
        abort_unless($request->user()->allows('cash.create'), 403, 'Tu cuenta no tiene permiso para agregar cajas.');

        $validated = $request->validate([
            'branch_id' => ['required', 'uuid', Rule::exists('branches', 'id')->where('tenant_id', $tenant->id)],
            'name'      => ['required', 'string', 'max:100', Rule::unique('cash_registers', 'name')->where('branch_id', $request->input('branch_id'))],
        ], [
            'branch_id.required' => 'Elige la sede de la caja.',
            'name.required'      => 'Escribe el nombre de la caja.',
            'name.unique'        => 'Esa sede ya tiene una caja con ese nombre.',
        ]);
        Access::assertBranch($request->user(), $validated['branch_id'], true);

        $id = (string) Str::uuid();
        DB::table('cash_registers')->insert([
            'id' => $id, 'tenant_id' => $tenant->id, 'branch_id' => $validated['branch_id'], 'name' => trim($validated['name']),
            'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);

        Audit::log($request, 'cash_register.created', [
            'tenant' => $tenant->id, 'entity_type' => 'cash_register', 'entity_id' => $id, 'entity_label' => trim($validated['name']),
            'branch_id' => $validated['branch_id'], 'after' => ['name' => trim($validated['name']), 'status' => 'active'],
        ]);

        return response()->json(['id' => $id], 201);
    }

    public function updateRegister(Request $request, string $register)
    {
        $tenant = $this->tenant($request);
        abort_unless($request->user()->allows('cash.create'), 403, 'Tu cuenta no tiene permiso para cambiar las cajas.');

        $row = DB::table('cash_registers')->where('tenant_id', $tenant->id)->where('id', $register)->first();
        abort_unless($row, 404);
        Access::assertBranch($request->user(), $row->branch_id, true);

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

        [$before, $after] = Audit::changed(['name' => $row->name, 'status' => $row->status], ['name' => trim($validated['name']), 'status' => $validated['status']]);

        if ($after) {
            Audit::log($request, 'cash_register.updated', [
                'tenant' => $tenant->id, 'entity_type' => 'cash_register', 'entity_id' => $row->id, 'entity_label' => trim($validated['name']),
                'branch_id' => $row->branch_id, 'before' => $before, 'after' => $after,
            ]);
        }

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
            Access::assertBranch($request->user(), $row->branch_id, true);
            abort_if($row->status !== 'active', 422, 'Esta caja está desactivada.');
            abort_if(DB::table('cash_sessions')->where('cash_register_id', $row->id)->where('status', 'open')->exists(), 409, 'Esta caja ya está abierta.');

            $id = (string) Str::uuid();
            DB::table('cash_sessions')->insert([
                'id' => $id, 'tenant_id' => $tenant->id, 'branch_id' => $row->branch_id, 'cash_register_id' => $row->id,
                'status' => 'open', 'opened_by' => $request->user()->id, 'opening_amount' => round((float) $validated['opening_amount'], 2), 'opened_at' => now(),
            ] + Currency::column($tenant));

            Audit::log($request, 'cash.opened', [
                'tenant' => $tenant->id, 'entity_type' => 'cash_session', 'entity_id' => $id, 'entity_label' => $row->name,
                'branch_id' => $row->branch_id, 'after' => ['amount' => round((float) $validated['opening_amount'], 2)],
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
            Access::assertBranch($request->user(), $row->branch_id, true);
            $this->assertOwn($request->user(), $row);
            abort_if($row->status !== 'open', 409, 'Este turno de caja ya está cerrado.');

            $amount = round((float) $validated['amount'], 2);

            if (Cash::SIGNS[$validated['type']] < 0 && $amount > Cash::present($row)['expected_amount']) {
                throw ValidationException::withMessages(['amount' => 'En la caja no hay tanto efectivo.']);
            }

            DB::table('cash_movements')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'cash_session_id' => $row->id,
                'type' => $validated['type'], 'amount' => $amount, 'description' => trim($validated['description']),
                'created_by' => $request->user()->id, 'created_at' => now(),
            ]);

            Audit::log($request, 'cash.movement', [
                'tenant' => $tenant->id, 'entity_type' => 'cash_session', 'entity_id' => $row->id,
                'entity_label' => DB::table('cash_registers')->where('id', $row->cash_register_id)->value('name'), 'branch_id' => $row->branch_id,
                'after' => ['type' => Cash::MANUAL[$validated['type']], 'amount' => $amount, 'reason' => trim($validated['description'])],
            ]);

            return response()->json(Cash::present($row, true), 201);
        });
    }

    public function close(Request $request, string $session)
    {
        $tenant = $this->tenant($request);

        $validated = $request->validate([
            'counted_amount' => 'required|numeric|min:0|max:9999999',
            'notes'          => 'nullable|string|max:500',
            'count'          => 'nullable|array|max:30',
            'count.*.value'  => 'required|numeric|min:0.01|max:1000',
            'count.*.quantity' => 'required|integer|min:0|max:100000',
        ], [
            'count.*.quantity.integer' => 'Escribe cuántos billetes o monedas hay, en números enteros.',
            'counted_amount.required' => 'Escribe cuánto efectivo contaste.',
            'counted_amount.numeric'  => 'Escribe el monto como un número.',
        ]);

        return DB::transaction(function () use ($tenant, $session, $validated, $request) {
            $row = $this->session($tenant, $session, true);
            Access::assertBranch($request->user(), $row->branch_id, true);
            $this->assertOwn($request->user(), $row);
            abort_if($row->status !== 'open', 409, 'Este turno de caja ya está cerrado.');

            $expected = Cash::present($row)['expected_amount'];
            $counted = round((float) $validated['counted_amount'], 2);
            $count = collect($validated['count'] ?? [])
                ->filter(fn ($c) => (int) $c['quantity'] > 0)
                ->map(fn ($c) => ['value' => round((float) $c['value'], 2), 'quantity' => (int) $c['quantity']])
                ->values();

            if ($count->isNotEmpty() && abs($count->sum(fn ($c) => $c['value'] * $c['quantity']) - $counted) > 0.005) {
                throw ValidationException::withMessages(['counted_amount' => 'El total no coincide con los billetes y monedas que contaste. Revisa las cantidades.']);
            }
            $difference = round($counted - $expected, 2);
            $notes = trim((string) ($validated['notes'] ?? '')) ?: null;
            $tolerance = Sales::rules($tenant)['cash_tolerance'];
            $overTolerance = abs($difference) > $tolerance + 0.001;

            if ($overTolerance && !$notes) {
                throw ValidationException::withMessages([
                    'notes' => 'Hay una diferencia de ' . \App\Support\Currency::format(abs($difference), \App\Support\Currency::of($tenant)) . ($difference < 0 ? ' (falta)' : ' (sobra)') . ': explica a qué se debe para cerrar.',
                ]);
            }

            $reviewOver = Sales::rules($tenant)['cash_review_over'];
            $toReview = Cash::reviewReady() && $reviewOver > 0 && abs($difference) > $reviewOver + 0.001;

            DB::table('cash_sessions')->where('id', $row->id)->update([
                'status' => 'closed', 'closed_by' => $request->user()->id, 'closed_at' => now(),
                'expected_amount' => $expected, 'closing_amount' => $counted, 'difference' => $difference, 'closing_notes' => $notes,
            ] + ($toReview ? ['review_status' => 'pending'] : []) + ($count->isNotEmpty() && Schema::hasColumn('cash_sessions', 'closing_count') ? ['closing_count' => json_encode($count->all())] : []));

            Audit::log($request, 'cash.closed', [
                'tenant' => $tenant->id, 'entity_type' => 'cash_session', 'entity_id' => $row->id,
                'entity_label' => DB::table('cash_registers')->where('id', $row->cash_register_id)->value('name'), 'branch_id' => $row->branch_id,
                'after' => array_filter(['counted' => $counted, 'expected' => $expected, 'difference' => $difference, 'notes' => $notes, 'status' => $toReview ? Cash::REVIEW_LABELS['pending'] : null], fn ($v) => $v !== null),
            ]);

            if ($toReview) {
                $this->notifyReview($tenant, $row, $difference, $request->user());
            } elseif ($overTolerance) {
                $this->notifyDifference($tenant, $row, $difference, $request->user());
            }

            return response()->json(Cash::present($this->session($tenant, $row->id), true));
        });
    }

    public function review(Request $request, string $session)
    {
        $tenant = $this->tenant($request);
        abort_unless(Cash::reviewReady(), 503, 'Falta actualizar la base de datos para revisar cierres de caja: ejecuta php artisan migrate.');

        $validated = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'observed'])],
            'notes'    => 'nullable|string|max:500',
        ], [
            'decision.required' => 'Elige si apruebas u observas el cierre.',
        ]);

        $notes = trim((string) ($validated['notes'] ?? '')) ?: null;

        if ($validated['decision'] === 'observed' && !$notes) {
            throw ValidationException::withMessages(['notes' => 'Escribe qué observas del cierre, para que quien lo cerró sepa qué revisar.']);
        }

        return DB::transaction(function () use ($tenant, $session, $validated, $notes, $request) {
            $row = $this->session($tenant, $session, true);
            Access::assertBranch($request->user(), $row->branch_id, true);
            abort_if(($row->review_status ?? null) !== 'pending', 409, 'Este cierre no está en revisión.');
            abort_if($row->closed_by === $request->user()->id && !$request->user()->isTenantAdmin(), 403, 'Un cierre en revisión lo aprueba otra persona, no quien lo cerró.');

            DB::table('cash_sessions')->where('id', $row->id)->update([
                'review_status' => $validated['decision'], 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_notes' => $notes,
            ]);

            $register = DB::table('cash_registers')->where('id', $row->cash_register_id)->value('name');

            Audit::log($request, 'cash.reviewed', [
                'tenant' => $tenant->id, 'entity_type' => 'cash_session', 'entity_id' => $row->id, 'entity_label' => $register, 'branch_id' => $row->branch_id,
                'before' => ['status' => Cash::REVIEW_LABELS['pending']],
                'after'  => array_filter(['status' => Cash::REVIEW_LABELS[$validated['decision']], 'notes' => $notes, 'difference' => round((float) $row->difference, 2)], fn ($v) => $v !== null),
            ]);

            try {
                if (Notifier::available() && $row->closed_by && $row->closed_by !== $request->user()->id) {
                    $who = \App\Support\Person::name($request->user());
                    $title = $validated['decision'] === 'approved' ? 'Tu cierre de caja fue aprobado' : 'Tu cierre de caja fue observado';
                    Notifier::send($tenant->id, $row->closed_by, 'cash.reviewed', $title, implode(' · ', array_filter(["{$register}. Lo revisó {$who}.", $notes])), '/cash', 'cash_session', $row->id);
                }
            } catch (\Throwable $e) {
                report($e);
            }

            $fresh = $this->session($tenant, $row->id);

            return response()->json(Cash::present($fresh, true) + [
                'register_name' => $register,
                'branch_name'   => DB::table('branches')->where('id', $row->branch_id)->value('name'),
            ]);
        });
    }

    private function notifyReview(Tenant $tenant, object $session, float $difference, User $actor): void
    {
        try {
            if (!Notifier::available()) {
                return;
            }

            $register = DB::table('cash_registers')->where('id', $session->cash_register_id)->value('name');
            $branch = DB::table('branches')->where('id', $session->branch_id)->value('name');
            $who = \App\Support\Person::name($actor);
            $body = ($difference < 0 ? 'Faltan ' : 'Sobran ') . Currency::format(abs($difference), $session->currency ?? Currency::of($tenant)) . " en {$register}. La cerró {$who}: revísalo y apruébalo u obsérvalo.";

            foreach (Notifier::staffWith($tenant->id, 'cash.approve', $session->branch_id) as $userId) {
                if ($userId !== $actor->id) {
                    Notifier::send($tenant->id, $userId, 'cash.review', 'Cierre de caja en revisión', implode(' · ', array_filter([$body, $branch])), '/cash', 'cash_session', $session->id);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function notifyDifference(Tenant $tenant, object $session, float $difference, User $actor): void
    {
        try {
            if (!Notifier::available()) {
                return;
            }

            $register = DB::table('cash_registers')->where('id', $session->cash_register_id)->value('name');
            $branch = DB::table('branches')->where('id', $session->branch_id)->value('name');
            $who = \App\Support\Person::name($actor);
            $body = ($difference < 0 ? 'Faltan ' : 'Sobran ') . \App\Support\Currency::format(abs($difference), $session->currency ?? \App\Support\Currency::of($tenant)) . " en {$register}. La cerró {$who}.";

            foreach (array_unique([...Notifier::adminIds($tenant->id), ...Notifier::managerIds($tenant->id, $session->branch_id)]) as $userId) {
                if ($userId !== $actor->id) {
                    Notifier::send($tenant->id, $userId, 'cash.difference', 'Cierre de caja con diferencia', implode(' · ', array_filter([$body, $branch])), '/cash');
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
