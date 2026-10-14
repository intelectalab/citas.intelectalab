<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AvailabilityBlock;
use App\Models\Branch;
use App\Models\Professional;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AppointmentFlow;
use App\Support\AppointmentScheduler;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\Access;

class AvailabilityBlockController extends Controller
{
    private const LOCAL_FORMAT = 'Y-m-d\TH:i';

    private const AFFECTED_STATUSES = [
        AppointmentFlow::PENDING,
        AppointmentFlow::CONFIRMED,
        AppointmentFlow::CHECKED_IN,
        AppointmentFlow::IN_PROGRESS,
    ];

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 403);

        return $tenant;
    }

    private function timezone(Tenant $tenant): string
    {
        return $tenant->timezone ?: 'America/Lima';
    }

    private function ownProfessionalId(User $user): ?string
    {
        if ($user->isTenantAdmin() || !$user->roles()->where('slug', 'professional')->exists()) {
            return null;
        }

        return Professional::where('tenant_id', $user->tenant_id)->where('user_id', $user->id)->value('id') ?? 'none';
    }

    public function options(Request $request)
    {
        $tenant = $this->tenant($request);
        $own = $this->ownProfessionalId($request->user());

        $kinds = fn (array $keys) => collect($keys)->map(fn (string $key) => ['key' => $key, 'label' => AvailabilityBlock::KINDS[$key]])->values();

        return response()->json([
            'branches'           => Branch::where('tenant_id', $tenant->id)->where('status', 'active')->orderBy('name')->get(['id', 'name']),
            'professionals'      => Professional::where('tenant_id', $tenant->id)
                ->when($own, fn ($q) => $q->where('id', $own))
                ->with('status')
                ->orderBy('display_name')
                ->get()
                ->reject(fn (Professional $p) => $p->status?->slug === 'inactive')
                ->map(fn (Professional $p) => ['id' => $p->id, 'display_name' => $p->display_name])
                ->values(),
            'professional_kinds' => $kinds(AvailabilityBlock::PROFESSIONAL_KINDS),
            'branch_kinds'       => $kinds(AvailabilityBlock::BRANCH_KINDS),
            'can_block_branch'   => $own === null,
            'can_manage_holidays' => $request->user()->allows('holidays.edit'),
        ]);
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);

        $request->validate([
            'professional_id' => 'nullable|uuid',
            'branch_id'       => 'nullable|uuid',
            'from'            => 'nullable|date',
            'to'              => 'nullable|date',
            'period'          => 'nullable|in:current,past',
        ]);

        $timezone = $this->timezone($tenant);
        $own = $this->ownProfessionalId($request->user());
        $past = $request->input('period') === 'past';

        $from = $request->filled('from') ? Carbon::parse($request->input('from'), $timezone) : now();

        $blocks = AvailabilityBlock::where('tenant_id', $tenant->id)
            ->with(['professional:id,display_name', 'branch:id,name'])
            ->when($own, fn ($q) => $q->where(fn ($q) => $q->where('professional_id', $own)->orWhereNull('professional_id')))
            ->when($request->filled('professional_id'), fn ($q) => $q->where('professional_id', $request->input('professional_id')))
            ->when($request->filled('branch_id'), fn ($q) => $q->where('branch_id', $request->input('branch_id')))
            ->when(Access::branchLimit($request->user()), fn ($q, $id) => $q->where(fn ($q) => $q->where('branch_id', $id)->orWhereIn('professional_id', Access::professionalsAt($id))))
            ->when($past, fn ($q) => $q->where('ends_at', '<=', now()->toIso8601String())->orderByDesc('starts_at')->limit(200))
            ->when(!$past, fn ($q) => $q->where('ends_at', '>', $from->toIso8601String())->orderBy('starts_at'))
            ->when($request->filled('to'), fn ($q) => $q->where('starts_at', '<', Carbon::parse($request->input('to'), $timezone)->toIso8601String()))
            ->get();

        return response()->json($blocks->map(fn (AvailabilityBlock $block) => $this->present($block, $tenant, $own)));
    }

    public function show(Request $request, string $id)
    {
        $tenant = $this->tenant($request);
        $own = $this->ownProfessionalId($request->user());
        $block = $this->find($tenant, $id, $own, false);
        $this->assertBranch($request, $block->professional_id, $block->branch_id);

        return response()->json($this->present($block, $tenant, $own) + [
            'affected' => $this->affectedList($tenant, $block->professional_id, $block->branch_id, Carbon::parse($block->starts_at), Carbon::parse($block->ends_at)),
        ]);
    }

    public function affected(Request $request)
    {
        $tenant = $this->tenant($request);
        $own = $this->ownProfessionalId($request->user());
        [$data, $startsAt, $endsAt] = $this->validated($request, $tenant, $own, false);

        return response()->json($this->affectedList($tenant, $data['professional_id'], $data['branch_id'], $startsAt, $endsAt));
    }

    public function store(Request $request)
    {
        $tenant = $this->tenant($request);
        $own = $this->ownProfessionalId($request->user());
        [$data, $startsAt, $endsAt] = $this->validated($request, $tenant, $own);

        $block = AvailabilityBlock::create($this->columns($data, $startsAt, $endsAt) + [
            'tenant_id' => $tenant->id,
        ] + (AvailabilityBlock::hasKind() ? ['created_by' => $request->user()->id] : []));

        return response()->json($this->present($block->fresh(['professional:id,display_name', 'branch:id,name']), $tenant, $own), 201);
    }

    public function update(Request $request, string $id)
    {
        $tenant = $this->tenant($request);
        $own = $this->ownProfessionalId($request->user());
        $block = $this->find($tenant, $id, $own);
        $this->assertBranch($request, $block->professional_id, $block->branch_id);
        [$data, $startsAt, $endsAt] = $this->validated($request, $tenant, $own);

        $block->update($this->columns($data, $startsAt, $endsAt));

        return response()->json($this->present($block->fresh(['professional:id,display_name', 'branch:id,name']), $tenant, $own));
    }

    public function destroy(Request $request, string $id)
    {
        $tenant = $this->tenant($request);
        $block = $this->find($tenant, $id, $this->ownProfessionalId($request->user()));
        $this->assertBranch($request, $block->professional_id, $block->branch_id);
        $block->delete();

        return response()->json(['message' => 'Bloqueo eliminado correctamente']);
    }

    private function assertBranch(Request $request, ?string $professionalId, ?string $branchId): void
    {
        $limit = Access::branchLimit($request->user());

        if ($limit === null) {
            return;
        }

        $mine = $professionalId ? Access::worksAt($professionalId, $limit) : $branchId === $limit;
        abort_unless($mine, 403, Access::otherBranch($request->user()));
    }

    private function find(Tenant $tenant, string $id, ?string $own, bool $manage = true): AvailabilityBlock
    {
        $block = AvailabilityBlock::where('tenant_id', $tenant->id)
            ->with(['professional:id,display_name', 'branch:id,name'])
            ->findOrFail($id);

        if ($own && $block->professional_id !== $own) {
            abort_if($manage || $block->professional_id, 403, 'Solo puedes cambiar tus propias ausencias.');
        }

        return $block;
    }

    private function validated(Request $request, Tenant $tenant, ?string $own, bool $full = true): array
    {
        $validated = $request->validate([
            'professional_id' => 'nullable|uuid|required_without:branch_id',
            'branch_id'       => 'nullable|uuid|required_without:professional_id',
            'starts_at'       => 'required|date_format:' . self::LOCAL_FORMAT,
            'ends_at'         => 'required|date_format:' . self::LOCAL_FORMAT,
            'reason'          => ($full ? 'required' : 'nullable') . '|string|max:255',
            'kind'            => ['nullable', Rule::in(array_keys(AvailabilityBlock::KINDS))],
        ], [
            'professional_id.required_without' => 'Elige a quién se bloquea la agenda.',
            'branch_id.required_without'       => 'Elige a quién se bloquea la agenda.',
            'starts_at.required'               => 'Indica desde cuándo.',
            'ends_at.required'                 => 'Indica hasta cuándo.',
            'reason.required'                  => 'Escribe el motivo.',
        ]);

        $professionalId = $validated['professional_id'] ?? null;
        $branchId = $professionalId ? null : ($validated['branch_id'] ?? null);

        if ($professionalId) {
            Professional::where('tenant_id', $tenant->id)->findOrFail($professionalId);
            abort_if($own && $professionalId !== $own, 403, 'Solo puedes registrar tus propias ausencias.');
        } else {
            Branch::where('tenant_id', $tenant->id)->findOrFail($branchId);
            abort_if($own !== null, 403, 'Solo el personal administrativo puede cerrar una sede.');
        }

        $this->assertBranch($request, $professionalId, $branchId);

        $timezone = $this->timezone($tenant);
        $startsAt = Carbon::createFromFormat(self::LOCAL_FORMAT, $validated['starts_at'], $timezone)->startOfMinute();
        $endsAt = Carbon::createFromFormat(self::LOCAL_FORMAT, $validated['ends_at'], $timezone)->startOfMinute();

        if ($endsAt->lte($startsAt)) {
            throw ValidationException::withMessages(['ends_at' => 'La fecha de fin debe ser posterior a la de inicio.']);
        }

        $kind = $validated['kind'] ?? AvailabilityBlock::KIND_OTHER;
        $allowed = $professionalId ? AvailabilityBlock::PROFESSIONAL_KINDS : AvailabilityBlock::BRANCH_KINDS;

        if (!in_array($kind, $allowed, true)) {
            throw ValidationException::withMessages(['kind' => $professionalId
                ? 'Ese tipo es para una sede completa, no para una persona.'
                : 'Ese tipo es para una persona, no para una sede completa.']);
        }

        return [[
            'professional_id' => $professionalId,
            'branch_id'       => $branchId,
            'kind'            => $kind,
            'reason'          => trim((string) ($validated['reason'] ?? '')),
        ], $startsAt, $endsAt];
    }

    private function columns(array $data, Carbon $startsAt, Carbon $endsAt): array
    {
        return [
            'professional_id' => $data['professional_id'],
            'branch_id'       => $data['branch_id'],
            'starts_at'       => $startsAt->toIso8601String(),
            'ends_at'         => $endsAt->toIso8601String(),
            'reason'          => $data['reason'],
        ] + (AvailabilityBlock::hasKind() ? ['kind' => $data['kind']] : []);
    }

    private function affectedQuery(Tenant $tenant, ?string $professionalId, ?string $branchId, Carbon $startsAt, Carbon $endsAt): Builder
    {
        return Appointment::where('tenant_id', $tenant->id)
            ->whereIn('status', self::AFFECTED_STATUSES)
            ->when($professionalId, fn ($q) => $q->where('professional_id', $professionalId))
            ->when(!$professionalId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('starts_at', '<', $endsAt->toIso8601String())
            ->where('ends_at', '>', $startsAt->toIso8601String())
            ->where('ends_at', '>', now()->toIso8601String());
    }

    private function affectedList(Tenant $tenant, ?string $professionalId, ?string $branchId, Carbon $startsAt, Carbon $endsAt): array
    {
        return $this->affectedQuery($tenant, $professionalId, $branchId, $startsAt, $endsAt)
            ->with(['customer:id,first_name,last_name', 'service:id,name', 'professional:id,display_name', 'branch:id,timezone'])
            ->orderBy('starts_at')
            ->limit(100)
            ->get()
            ->map(function (Appointment $a) use ($tenant) {
                $timezone = AppointmentScheduler::timezone($a->branch ?? new Branch(), $tenant);

                return [
                    'id'           => $a->id,
                    'code'         => $a->code,
                    'status'       => $a->status,
                    'status_name'  => AppointmentFlow::name($tenant, $a->status),
                    'starts_at'    => $a->starts_at->copy()->setTimezone($timezone)->format(self::LOCAL_FORMAT),
                    'ends_at'      => $a->ends_at->copy()->setTimezone($timezone)->format(self::LOCAL_FORMAT),
                    'customer'     => $a->customer ? trim("{$a->customer->first_name} {$a->customer->last_name}") : null,
                    'service'      => $a->service?->name,
                    'professional' => $a->professional?->display_name,
                ];
            })
            ->all();
    }

    private function present(AvailabilityBlock $block, Tenant $tenant, ?string $own): array
    {
        $timezone = $this->timezone($tenant);
        $startsAt = Carbon::parse($block->starts_at);
        $endsAt = Carbon::parse($block->ends_at);
        $kind = $block->kindKey();

        return [
            'id'              => $block->id,
            'professional_id' => $block->professional_id,
            'branch_id'       => $block->branch_id,
            'professional'    => $block->professional ? ['id' => $block->professional->id, 'display_name' => $block->professional->display_name] : null,
            'branch'          => $block->branch ? ['id' => $block->branch->id, 'name' => $block->branch->name] : null,
            'scope'           => $block->professional_id ? 'professional' : 'branch',
            'kind'            => $kind,
            'kind_label'      => AvailabilityBlock::KINDS[$kind] ?? AvailabilityBlock::KINDS[AvailabilityBlock::KIND_OTHER],
            'overridable'     => $block->isOverridable(),
            'reason'          => $block->reason,
            'starts_at'       => $startsAt->copy()->setTimezone($timezone)->format(self::LOCAL_FORMAT),
            'ends_at'         => $endsAt->copy()->setTimezone($timezone)->format(self::LOCAL_FORMAT),
            'state'           => $endsAt->lte(now()) ? 'past' : ($startsAt->lte(now()) ? 'current' : 'upcoming'),
            'affected_count'  => $this->affectedQuery($tenant, $block->professional_id, $block->branch_id, $startsAt, $endsAt)->count(),
            'can_manage'      => $own === null || $block->professional_id === $own,
        ];
    }
}
