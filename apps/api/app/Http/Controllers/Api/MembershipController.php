<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Service;
use App\Models\Tenant;
use App\Support\Audit;
use App\Support\Currency;
use App\Support\Memberships;
use App\Support\Person;
use App\Support\Sales;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MembershipController extends Controller
{
    private function ready(Request $request): Tenant
    {
        abort_unless(Memberships::ready(), 503, Memberships::NOT_READY);
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 403);
        Memberships::expireDue($tenant);

        return $tenant;
    }

    private function plans(Tenant $tenant): array
    {
        $plans = DB::table('membership_plans')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->orderBy('name')->get();
        $items = Memberships::planItems($plans->pluck('id')->all());
        $members = DB::table('memberships')->where('tenant_id', $tenant->id)->where('status', 'active')->whereIn('membership_plan_id', $plans->pluck('id'))->groupBy('membership_plan_id')->selectRaw('membership_plan_id, count(*) AS total')->pluck('total', 'membership_plan_id');

        return $plans->map(fn ($p) => Memberships::presentPlan($tenant, $p, $items[$p->id] ?? collect(), (int) ($members[$p->id] ?? 0)))->values()->all();
    }

    private function onePlan(Tenant $tenant, string $id): array
    {
        return collect($this->plans($tenant))->firstWhere('id', $id) ?? [];
    }

    public function index(Request $request)
    {
        $tenant = $this->ready($request);
        $user = $request->user();
        $customerId = $request->validate(['customer_id' => 'nullable|uuid'])['customer_id'] ?? null;

        $rows = DB::table('memberships')
            ->where('tenant_id', $tenant->id)
            ->when($customerId, fn ($q, $id) => $q->where('customer_id', $id))
            ->orderByDesc('created_at')
            ->limit(1000)
            ->get();

        return response()->json([
            'currency'    => Currency::of($tenant),
            'memberships' => Memberships::presentMany($tenant, $rows),
            'plans'       => $customerId ? [] : $this->plans($tenant),
            'options'     => [
                'periods'  => collect(Memberships::PERIODS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
                'scopes'   => collect(Memberships::SCOPES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
                'statuses' => collect(Memberships::STATUS_LABELS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
                'services' => Service::where('tenant_id', $tenant->id)->whereIn('kind', $tenant->hasModule('classes') ? [Service::KIND_INDIVIDUAL, Service::KIND_GROUP] : [Service::KIND_INDIVIDUAL])->with('status')->orderBy('name')->get()
                    ->reject(fn (Service $s) => $s->status?->slug === 'inactive')
                    ->map(fn (Service $s) => ['value' => $s->id, 'label' => $s->name, 'price' => round((float) $s->price, 2)])->values(),
            ],
            'can'         => [
                'create' => $user->allows('memberships.create'),
                'edit'   => $user->allows('memberships.edit'),
                'delete' => $user->allows('memberships.delete'),
                'sell'   => $tenant->hasModule('pos') && $user->allows('pos.create'),
            ],
        ]);
    }

    public function show(Request $request, string $membership)
    {
        $tenant = $this->ready($request);
        $row = DB::table('memberships')->where('tenant_id', $tenant->id)->where('id', $membership)->first();
        abort_unless($row, 404);

        return response()->json($this->detail($request, $tenant, $row));
    }

    private function detail(Request $request, Tenant $tenant, object $row): array
    {
        $user = $request->user();
        $zone = $tenant->zone();
        $data = Memberships::presentMany($tenant, collect([$row]))[0];
        $iso = fn ($value) => $value ? Carbon::parse($value)->toIso8601String() : null;
        $customer = DB::table('customers')->where('id', $row->customer_id)->first(['id', 'code', 'first_name', 'last_name', 'phone']);

        $usages = DB::table('membership_usages as u')
            ->join('sales as s', 's.id', '=', 'u.sale_id')
            ->join('services as sv', 'sv.id', '=', 'u.service_id')
            ->leftJoin('appointments as a', 'a.id', '=', 'u.appointment_id')
            ->where('u.membership_id', $row->id)
            ->orderByDesc('u.created_at')
            ->get(['u.id', 'u.quantity', 'u.period_start', 'u.created_at', 'sv.name as service', 's.id as sale_id', 's.code as sale_code', 's.status as sale_status', 'a.id as appointment_id', 'a.code as appointment_code'])
            ->map(fn ($u) => [
                'id'           => $u->id,
                'service'      => $u->service,
                'quantity'     => (int) $u->quantity,
                'period_start' => Carbon::parse($u->period_start)->toDateString(),
                'at'           => $iso($u->created_at),
                'sale'         => ['id' => $u->sale_id, 'code' => $u->sale_code, 'status' => $u->sale_status, 'status_label' => Sales::STATUS_LABELS[$u->sale_status] ?? $u->sale_status],
                'released'     => in_array($u->sale_status, ['void', 'refunded'], true),
                'appointment'  => $u->appointment_id ? ['id' => $u->appointment_id, 'code' => $u->appointment_code] : null,
            ])->values();

        $events = Schema::hasTable('membership_events') ? DB::table('membership_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.created_by')
            ->where('e.membership_id', $row->id)
            ->orderByDesc('e.created_at')
            ->get(['e.id', 'e.kind', 'e.detail', 'e.created_at', 'u.first_name', 'u.last_name'])
            ->map(fn ($e) => ['id' => $e->id, 'kind' => $e->kind, 'detail' => $e->detail, 'by' => Person::name($e) ?: null, 'at' => $iso($e->created_at)])
            ->values() : collect();

        $sale = $row->sale_id ? DB::table('sales')->where('id', $row->sale_id)->first(['id', 'code', 'status']) : null;
        $branch = !empty($row->branch_id) ? DB::table('branches')->where('id', $row->branch_id)->first(['id', 'name']) : null;
        $summary = in_array($row->status, Memberships::ALIVE, true) ? Memberships::summary($tenant, $row) : null;

        return $data + [
            'customer'      => $customer ? ['id' => $customer->id, 'code' => $customer->code, 'first_name' => $customer->first_name, 'last_name' => $customer->last_name, 'name' => Person::name($customer), 'phone' => $customer->phone] : null,
            'plan_id'       => $row->membership_plan_id,
            'plan_on_sale'  => DB::table('membership_plans')->where('id', $row->membership_plan_id)->whereNull('deleted_at')->where('status', 'active')->exists(),
            'discount_percent' => (float) Memberships::benefits($row)['discount_percent'],
            'discount_scope_label' => Memberships::SCOPES[Memberships::benefits($row)['discount_scope']] ?? '',
            'period_start'  => $summary['period_start'] ?? null,
            'grace_days'    => Memberships::graceDays($tenant),
            'sale'          => $sale ? ['id' => $sale->id, 'code' => $sale->code, 'status' => $sale->status, 'status_label' => Sales::STATUS_LABELS[$sale->status] ?? $sale->status] : null,
            'branch'        => $branch ? ['id' => $branch->id, 'name' => $branch->name] : null,
            'usages'        => $usages,
            'events'        => $events,
            'history_ready' => Schema::hasTable('membership_events'),
            'can'           => [
                'edit'   => $user->allows('memberships.edit'),
                'delete' => $user->allows('memberships.delete'),
                'sell'   => $tenant->hasModule('pos') && $user->allows('pos.create'),
            ],
        ];
    }

    private function audit(Request $request, Tenant $tenant, string $action, string $id, array $after, array $before = []): void
    {
        $row = DB::table('memberships')->where('id', $id)->first();

        if (!$row) {
            return;
        }

        $customer = DB::table('customers')->where('id', $row->customer_id)->first(['first_name', 'last_name']);

        Audit::log($request, $action, [
            'tenant'       => $tenant->id,
            'entity_type'  => 'membership',
            'entity_id'    => $row->id,
            'entity_label' => $row->code,
            'branch_id'    => $row->branch_id ?? null,
            'before'       => $before ?: null,
            'after'        => $after + array_filter(['membership' => $row->name, 'customer' => Person::name($customer) ?: null]),
        ]);
    }

    private function reason(Request $request, string $example): string
    {
        return trim($request->validate(
            ['reason' => 'required|string|min:3|max:255'],
            ['reason.required' => "Escribe el motivo. Ej. \"{$example}\".", 'reason.min' => "Escribe el motivo. Ej. \"{$example}\"."],
        )['reason']);
    }

    public function pause(Request $request, string $membership)
    {
        $tenant = $this->ready($request);
        $reason = $this->reason($request, 'viaja por un mes');
        $row = DB::transaction(fn () => Memberships::pause($tenant, $membership, $reason, $request->user()));
        $this->audit($request, $tenant, 'membership.paused', $row->id, ['reason' => $reason], ['status' => 'Vigente']);

        return response()->json($this->detail($request, $tenant, $row));
    }

    public function resume(Request $request, string $membership)
    {
        $tenant = $this->ready($request);
        $result = DB::transaction(fn () => Memberships::resume($tenant, $membership, $request->user()));
        $this->audit($request, $tenant, 'membership.resumed', $membership, ['paused_days' => $result['days'], 'ends_at' => $result['ends_at']], ['ends_at' => $result['before']]);

        return response()->json($this->detail($request, $tenant, $result['row']) + ['result' => ['days' => $result['days']]]);
    }

    public function cancel(Request $request, string $membership)
    {
        $tenant = $this->ready($request);
        $reason = $this->reason($request, 'se mudó de ciudad');
        $before = DB::table('memberships')->where('tenant_id', $tenant->id)->where('id', $membership)->value('status');
        $row = DB::transaction(fn () => Memberships::cancel($tenant, $membership, $reason, $request->user()));
        $this->audit($request, $tenant, 'membership.cancelled', $row->id, ['reason' => $reason], ['status' => Memberships::STATUS_LABELS[$before] ?? $before]);

        return response()->json($this->detail($request, $tenant, $row));
    }

    private function planInput(Request $request, Tenant $tenant, ?string $ignore = null): array
    {
        $data = $request->validate([
            'name'             => ['required', 'string', 'max:150'],
            'description'      => 'nullable|string|max:1000',
            'price'            => 'required|numeric|min:0.01|max:9999999',
            'billing_period'   => ['required', Rule::in(array_keys(Memberships::PERIODS))],
            'discount_percent' => 'nullable|numeric|min:0|max:100',
            'discount_scope'   => ['nullable', Rule::in(array_keys(Memberships::SCOPES))],
            'status'           => ['nullable', Rule::in(['active', 'inactive'])],
            'items'            => 'nullable|array|max:20',
            'items.*.service_id' => 'required|uuid',
            'items.*.sessions'   => 'required|integer|min:1|max:99',
        ], [
            'name.required'           => 'Escribe el nombre del plan.',
            'price.required'          => 'Escribe el precio.',
            'price.min'               => 'El precio debe ser mayor que cero.',
            'billing_period.required' => 'Elige cada cuánto se paga.',
            'discount_percent.max'    => 'El descuento va de 0 a 100 %.',
            'items.*.sessions.min'    => 'Cada servicio incluido lleva al menos 1 sesión.',
            'items.*.sessions.max'    => 'Un servicio incluye como máximo 99 sesiones por periodo.',
        ]);

        $name = trim($data['name']);
        $taken = DB::table('membership_plans')->where('tenant_id', $tenant->id)->whereNull('deleted_at')
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->when($ignore, fn ($q, $id) => $q->where('id', '!=', $id))->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => 'Ya hay un plan con ese nombre.']);
        }

        $items = collect($data['items'] ?? []);

        if ($items->pluck('service_id')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['items' => 'Un servicio aparece dos veces: deja uno solo con todas sus sesiones.']);
        }

        $known = DB::table('services')->where('tenant_id', $tenant->id)->whereIn('id', $items->pluck('service_id'))->pluck('id')->all();

        if (count($known) !== $items->count()) {
            throw ValidationException::withMessages(['items' => 'Uno de los servicios ya no existe. Quítalo y elige otro.']);
        }

        $discount = round((float) ($data['discount_percent'] ?? 0), 2);

        if ($discount <= 0 && $items->isEmpty()) {
            throw ValidationException::withMessages(['discount_percent' => 'Dale al plan al menos un beneficio: un descuento o servicios incluidos.']);
        }

        return [
            'row'   => [
                'name'             => $name,
                'description'      => trim((string) ($data['description'] ?? '')) ?: null,
                'price'            => round((float) $data['price'], 2),
                'billing_period'   => $data['billing_period'],
                'discount_percent' => $discount,
                'discount_scope'   => $data['discount_scope'] ?? 'services',
                'sessions_included' => (int) $items->sum('sessions'),
                'status'           => $data['status'] ?? 'active',
                'currency'         => Currency::of($tenant),
            ],
            'items' => $items->values()->all(),
        ];
    }

    private function savePlan(Tenant $tenant, array $input, ?string $id = null): string
    {
        return DB::transaction(function () use ($tenant, $input, $id) {
            if ($id) {
                DB::table('membership_plans')->where('id', $id)->update($input['row'] + ['updated_at' => now()]);
                DB::table('membership_plan_items')->where('membership_plan_id', $id)->delete();
            } else {
                $id = (string) Str::uuid();
                DB::table('membership_plans')->insert($input['row'] + ['id' => $id, 'tenant_id' => $tenant->id, 'created_at' => now(), 'updated_at' => now()]);
            }

            foreach ($input['items'] as $position => $item) {
                DB::table('membership_plan_items')->insert([
                    'id'                 => (string) Str::uuid(),
                    'tenant_id'          => $tenant->id,
                    'membership_plan_id' => $id,
                    'service_id'         => $item['service_id'],
                    'sessions'           => (int) $item['sessions'],
                    'position'           => $position,
                ]);
            }

            return $id;
        });
    }

    private function snapshot(Tenant $tenant, string $id): array
    {
        $plan = $this->onePlan($tenant, $id);

        return [
            'name'     => $plan['name'] ?? null,
            'price'    => $plan['price'] ?? null,
            'period'   => $plan['period_label'] ?? null,
            'benefits' => $plan['benefits_text'] ?? null,
            'status'   => isset($plan['status']) ? ($plan['status'] === 'active' ? 'Se vende' : 'Apagado') : null,
        ];
    }

    public function storePlan(Request $request)
    {
        $tenant = $this->ready($request);
        $id = $this->savePlan($tenant, $this->planInput($request, $tenant));
        $after = $this->snapshot($tenant, $id);
        Audit::log($request, 'membership_plan.created', ['tenant' => $tenant->id, 'entity_type' => 'membership_plan', 'entity_id' => $id, 'entity_label' => $after['name'], 'after' => $after]);

        return response()->json($this->onePlan($tenant, $id), 201);
    }

    public function updatePlan(Request $request, string $plan)
    {
        $tenant = $this->ready($request);
        $row = DB::table('membership_plans')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->where('id', $plan)->first();
        abort_unless($row, 404);
        $old = $this->snapshot($tenant, $row->id);
        $this->savePlan($tenant, $this->planInput($request, $tenant, $row->id), $row->id);
        [$before, $after] = Audit::changed($old, $this->snapshot($tenant, $row->id));

        if ($after) {
            Audit::log($request, 'membership_plan.updated', ['tenant' => $tenant->id, 'entity_type' => 'membership_plan', 'entity_id' => $row->id, 'entity_label' => $row->name, 'before' => $before, 'after' => $after]);
        }

        return response()->json($this->onePlan($tenant, $row->id));
    }

    public function destroyPlan(Request $request, string $plan)
    {
        $tenant = $this->ready($request);
        $row = DB::table('membership_plans')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->where('id', $plan)->first();
        abort_unless($row, 404);

        $inCart = DB::table('sale_items as i')->join('sales as s', 's.id', '=', 'i.sale_id')->where('i.item_type', 'membership')->where('i.reference_id', $row->id)->whereIn('s.status', ['pending', 'partial'])->exists();

        if ($inCart) {
            throw ValidationException::withMessages(['plan' => 'Este plan está en una venta que quedó por cobrar. Cóbrala o anúlala antes de quitarlo.']);
        }

        $sold = DB::table('memberships')->where('membership_plan_id', $row->id)->exists();
        $snapshot = $this->snapshot($tenant, $row->id);

        if ($sold) {
            DB::table('membership_plans')->where('id', $row->id)->update(['deleted_at' => now(), 'status' => 'inactive', 'updated_at' => now()]);
        } else {
            DB::table('membership_plans')->where('id', $row->id)->delete();
        }

        Audit::log($request, 'membership_plan.deleted', ['tenant' => $tenant->id, 'entity_type' => 'membership_plan', 'entity_id' => $row->id, 'entity_label' => $row->name, 'before' => $snapshot]);

        return response()->json(['kept' => $sold]);
    }
}
