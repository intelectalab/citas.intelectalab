<?php

namespace App\Http\Controllers\Api;

use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\ProfessionalSchedule;
use App\Models\Service;
use App\Models\Tenant;
use App\Support\Access;
use App\Support\AppointmentFlow;
use App\Support\AppointmentScheduler;
use App\Support\Cart;
use App\Support\Currency;
use App\Support\Invoicing;
use App\Support\Sales;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PosController extends SaleController
{
    private const WALK_IN_SLOTS = 6;

    private function cartInput(Request $request): array
    {
        return $request->validate([
            'branch_id'               => 'nullable|uuid',
            'customer_id'             => 'nullable|uuid',
            'coupon_code'             => 'nullable|string|max:40',
            'items'                   => 'present|array|max:' . Cart::MAX_ITEMS,
            'items.*.type'            => ['required', Rule::in(Cart::TYPES)],
            'items.*.id'              => 'required|uuid',
            'items.*.quantity'        => 'nullable|integer|min:1|max:999',
            'items.*.professional_id' => 'nullable|uuid',
            'items.*.details'         => 'nullable|array',
            'items.*.details.professional_id' => 'nullable|uuid',
            'items.*.details.branch_id'       => 'nullable|uuid',
            'items.*.details.slots'           => 'nullable|array|max:' . \App\Support\Packages::MAX_SLOTS,
            'items.*.details.slots.*.date'    => 'nullable|date_format:Y-m-d',
            'items.*.details.slots.*.time'    => ['nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'items.*.details.slots.*.professional_id' => 'nullable|uuid',
        ], [
            'items.max'               => 'Una venta lleva como máximo ' . Cart::MAX_ITEMS . ' líneas.',
            'items.*.quantity.min'    => 'La cantidad debe ser al menos 1.',
            'items.*.quantity.max'    => 'La cantidad es demasiado alta.',
            'items.*.quantity.integer' => 'La cantidad va en números enteros.',
        ]);
    }

    private function sale(Request $request, Tenant $tenant, string $id): object
    {
        $sale = DB::table('sales')->where('tenant_id', $tenant->id)->where('id', $id)->first();
        abort_unless($sale, 404);
        Access::assertBranch($request->user(), $sale->branch_id);

        return $sale;
    }

    private function branches(Request $request, Tenant $tenant)
    {
        return Branch::where('tenant_id', $tenant->id)
            ->where('status', 'active')
            ->when(Access::branchLimit($request->user()), fn ($q, $id) => $q->where('id', $id))
            ->orderBy('name')
            ->get(['id', 'name', 'timezone']);
    }

    public function board(Request $request)
    {
        $this->assertReady();
        $tenant = $this->tenant($request);
        $user = $request->user();
        $branches = $this->branches($request, $tenant);

        $wanted = $request->validate(['branch_id' => 'nullable|uuid'])['branch_id'] ?? null;
        $branch = $branches->firstWhere('id', $wanted) ?? $branches->firstWhere('id', $user->branch_id) ?? $branches->first();
        $rules = Sales::rules($tenant);

        $payload = [
            'branches'       => $branches->map(fn ($b) => ['id' => $b->id, 'name' => $b->name])->values(),
            'branch_id'      => $branch?->id,
            'currency'       => Currency::of($tenant),
            'tax'            => ['rate' => $rules['tax_rate'], 'included' => $rules['prices_include_tax']],
            'products_ready' => Cart::productsReady(),
            'can'            => [
                'create'   => $user->allows('pos.create'),
                'edit'     => $user->allows('pos.edit'),
                'charge'   => $user->allows('sales.create'),
                'walk_in'  => $user->allows('agenda.create'),
                'products' => $user->allows('products.create'),
                'packages' => $user->allows('packages.create'),
            ],
            'packages_enabled' => \App\Support\Packages::enabled($tenant),
            'pending'        => ['appointments' => [], 'sales' => []],
            'services'       => [],
            'products'       => [],
            'packages'       => [],
            'professionals'  => [],
        ];

        if (!$branch) {
            return response()->json($payload);
        }

        $assigned = DB::table('service_professionals')->where('tenant_id', $tenant->id)->get()->groupBy('service_id');
        $categories = DB::table('service_categories')->where('tenant_id', $tenant->id)->pluck('name', 'id');

        $payload['pending'] = [
            'appointments' => Cart::pendingAppointments($tenant, $branch, $this->ownProfessionalId($user)),
            'sales'        => Cart::pendingSales($tenant, $branch->id),
        ];

        $payload['services'] = Service::where('tenant_id', $tenant->id)
            ->where('kind', Service::KIND_INDIVIDUAL)
            ->with('status')
            ->orderBy('name')
            ->get()
            ->reject(fn (Service $s) => $s->status?->slug === 'inactive')
            ->map(fn (Service $s) => [
                'id'               => $s->id,
                'name'             => $s->name,
                'price'            => round((float) $s->price, 2),
                'duration_minutes' => $s->duration_minutes,
                'category'         => $categories[$s->category_id] ?? null,
                'professional_ids' => ($assigned[$s->id] ?? collect())->pluck('professional_id')->values(),
            ])->values();

        $payload['professionals'] = Professional::where('tenant_id', $tenant->id)
            ->whereIn('id', Access::professionalsAt($branch->id))
            ->with('status')
            ->orderBy('display_name')
            ->get()
            ->reject(fn (Professional $p) => $p->status?->slug === 'inactive')
            ->map(fn (Professional $p) => ['id' => $p->id, 'display_name' => $p->display_name])
            ->values();

        if ($payload['packages_enabled']) {
            $plans = DB::table('package_plans')->where('tenant_id', $tenant->id)->whereNull('deleted_at')->where('status', 'active')->orderBy('name')->get();
            $planItems = \App\Support\Packages::planItems($plans->pluck('id')->all());
            $payload['packages'] = $plans->map(fn ($p) => \App\Support\Packages::presentPlan($tenant, $p, $planItems[$p->id] ?? collect()))->values();
        }

        if (Cart::productsReady()) {
            $productCategories = DB::table('product_categories')->where('tenant_id', $tenant->id)->pluck('name', 'id');

            $payload['products'] = DB::table('products')
                ->where('tenant_id', $tenant->id)
                ->whereNull('deleted_at')
                ->where('status', 'active')
                ->whereIn('kind', Cart::SELLABLE)
                ->orderBy('name')
                ->get()
                ->map(fn ($p) => [
                    'id'       => $p->id,
                    'name'     => $p->name,
                    'sku'      => $p->sku,
                    'price'    => round((float) $p->price, 2),
                    'category' => $productCategories[$p->category_id] ?? null,
                ])->values();
        }

        return response()->json($payload);
    }

    public function preview(Request $request)
    {
        $this->assertReady();
        $tenant = $this->tenant($request);
        $input = $this->cartInput($request);
        $sale = $request->filled('sale_id') ? $this->sale($request, $tenant, (string) $request->input('sale_id')) : null;

        return response()->json(Cart::preview(Cart::resolve($tenant, $request->user(), $input, $sale)));
    }

    public function createSale(Request $request)
    {
        $this->assertReady();
        $tenant = $this->tenant($request);
        $sale = Cart::save($tenant, $request->user(), $this->cartInput($request));

        return response()->json(['sale' => Sales::present($sale), 'cart' => Cart::load($tenant, $sale)], 201);
    }

    public function showSale(Request $request, string $sale)
    {
        $this->assertReady();
        $tenant = $this->tenant($request);

        return response()->json(Cart::load($tenant, $this->sale($request, $tenant, $sale)));
    }

    public function updateSale(Request $request, string $sale)
    {
        $this->assertReady();
        $tenant = $this->tenant($request);
        $this->sale($request, $tenant, $sale);
        $row = Cart::save($tenant, $request->user(), $this->cartInput($request), $sale);

        return response()->json(['sale' => Sales::present($row), 'cart' => Cart::load($tenant, $row)]);
    }

    private function saleContext(Tenant $tenant, object $sale): array
    {
        $customer = $sale->customer_id ? Customer::where('tenant_id', $tenant->id)->find($sale->customer_id) : null;
        $count = DB::table('sale_items')->where('sale_id', $sale->id)->count();
        $first = DB::table('sale_items')->where('sale_id', $sale->id)->value('description');
        $item = $count > 1 ? "{$count} líneas" : $first;

        return $this->chargeContext($tenant, $sale, $sale->branch_id, $customer, $item, (float) $sale->subtotal, null, null);
    }

    public function chargeShow(Request $request, string $sale)
    {
        $this->assertReady();
        $tenant = $this->tenant($request);

        return response()->json($this->saleContext($tenant, $this->sale($request, $tenant, $sale)));
    }

    public function pay(Request $request, string $sale)
    {
        $this->assertReady();
        $tenant = $this->tenant($request);
        $user = $request->user();
        $row = $this->sale($request, $tenant, $sale);

        $validated = $request->validate([
            'method'          => ['required', Rule::in(array_keys(Sales::METHODS))],
            'amount'          => 'required|numeric|min:0.01|max:9999999',
            'received'        => 'nullable|numeric|min:0|max:9999999',
            'reference'       => 'nullable|string|max:190',
            'cash_session_id' => 'nullable|uuid',
            'idempotency_key' => 'nullable|string|max:190',
            'doc_type'        => 'nullable|string|max:20',
            'ruc'             => 'nullable|string|max:11',
            'business_name'   => 'nullable|string|max:255',
            'address'         => 'nullable|string|max:255',
        ], [
            'method.required'  => 'Elige el medio de pago.',
            'method.in'        => 'Ese medio de pago no está disponible.',
            'amount.required'  => 'Escribe el monto a cobrar.',
            'amount.numeric'   => 'Escribe el monto como un número.',
            'amount.min'       => 'El monto debe ser mayor que cero.',
            'received.numeric' => 'Escribe lo recibido como un número.',
        ]);

        Access::assertBranch($user, $row->branch_id, true);
        $doc = Invoicing::validated($tenant, $validated);
        $result = DB::transaction(fn () => Sales::pay($tenant, $row->id, $validated, $doc, $user));

        return response()->json($this->saleContext($tenant, $result['sale']) + ['change' => $result['change'], 'duplicate' => $result['duplicate'], 'packages' => $result['packages'] ?? []]);
    }

    public function invoice(Request $request, string $sale)
    {
        $this->assertReady();
        $tenant = $this->tenant($request);
        $row = $this->sale($request, $tenant, $sale);
        abort_unless($row->status === 'paid', 422, 'El comprobante se emite cuando la venta está pagada.');

        $doc = Invoicing::validated($tenant, $request->only(['doc_type', 'ruc', 'business_name', 'address']));
        DB::transaction(fn () => Invoicing::issue($tenant, $row, $doc, $request->user()));

        return response()->json($this->saleContext($tenant, DB::table('sales')->where('id', $row->id)->first()));
    }

    public function walkInOptions(Request $request)
    {
        $tenant = $this->tenant($request);

        $validated = $request->validate([
            'branch_id'  => 'required|uuid',
            'service_id' => 'required|uuid',
        ]);

        $branch = Branch::where('tenant_id', $tenant->id)->findOrFail($validated['branch_id']);
        Access::assertBranch($request->user(), $branch->id);
        $service = Service::where('tenant_id', $tenant->id)->with('status')->findOrFail($validated['service_id']);
        $timezone = AppointmentScheduler::timezone($branch, $tenant);
        $now = now($timezone)->addMinute()->startOfMinute();
        $scheduler = new AppointmentScheduler($tenant);
        $assigned = DB::table('service_professionals')->where('service_id', $service->id)->pluck('professional_id')->all();
        $own = $this->ownProfessionalId($request->user());

        $professionals = Professional::where('tenant_id', $tenant->id)
            ->whereIn('id', Access::professionalsAt($branch->id))
            ->when($assigned, fn ($q) => $q->whereIn('id', $assigned))
            ->when($own, fn ($q) => $q->where('id', $own))
            ->with('status')
            ->orderBy('display_name')
            ->get()
            ->reject(fn (Professional $p) => $p->status?->slug === 'inactive')
            ->map(function (Professional $professional) use ($scheduler, $service, $branch, $now) {
                $check = $scheduler->check($service, $professional, $branch, $now, (int) $service->duration_minutes);
                $slots = collect($scheduler->slots($service, $branch, $now, $professional)[0]['slots'] ?? [])
                    ->filter(fn (string $time) => $time > $now->format('H:i'))
                    ->take(self::WALK_IN_SLOTS)
                    ->values();
                $reason = $check['reasons'][0] ?? null;

                if ($reason) {
                    $message = trim(str_replace($professional->display_name, '', $reason['message']));
                    $reason['message'] = mb_strtoupper(mb_substr($message, 0, 1)) . mb_substr($message, 1);
                }

                return [
                    'id'           => $professional->id,
                    'display_name' => $professional->display_name,
                    'free_now'     => $check['ok'],
                    'now_reason'   => $reason ? trim($reason['message'] . ($reason['detail'] ? " ({$reason['detail']})" : '')) : null,
                    'slots'        => $slots,
                ];
            })
            ->values();

        return response()->json([
            'now'              => $now->format('H:i'),
            'duration_minutes' => (int) $service->duration_minutes,
            'price'            => round((float) $service->price, 2),
            'currency'         => Currency::of($tenant),
            'professionals'    => $professionals,
        ]);
    }

    public function walkIn(Request $request)
    {
        $tenant = $this->tenant($request);
        $user = $request->user();

        $validated = $request->validate([
            'customer_id'     => 'required|uuid',
            'service_id'      => 'required|uuid',
            'professional_id' => 'required|uuid',
            'branch_id'       => 'required|uuid',
            'at'              => ['required', 'string', 'regex:/^(now|([01]\d|2[0-3]):[0-5]\d)$/'],
        ], [
            'customer_id.required'     => 'Elige al cliente.',
            'service_id.required'      => 'Elige el servicio.',
            'professional_id.required' => 'Elige quién atiende.',
            'at.required'              => 'Elige la hora.',
            'at.regex'                 => 'Elige una de las horas que se muestran.',
        ]);

        $branch = Branch::where('tenant_id', $tenant->id)->findOrFail($validated['branch_id']);
        $timezone = AppointmentScheduler::timezone($branch, $tenant);
        $now = $validated['at'] === 'now';
        $start = $now ? now($timezone)->addMinute()->startOfMinute() : now($timezone)->setTimeFromTimeString($validated['at']);

        if (!$now && $start->lt(now($timezone))) {
            throw ValidationException::withMessages(['at' => 'Esa hora ya pasó. Elige otra.']);
        }

        $request->merge([
            'starts_at'        => $start->format(self::LOCAL_FORMAT),
            'duration_minutes' => null,
            'notes'            => null,
            'override_reason'  => null,
        ]);

        $response = $this->store($request);

        if ($response->getStatusCode() !== 201) {
            return $response;
        }

        $appointment = Appointment::where('tenant_id', $tenant->id)->findOrFail($response->getData(true)['id']);

        if ($now) {
            $from = $appointment->status;
            $to = AppointmentFlow::rule($tenant, 'agenda.checkin_step') ? AppointmentFlow::CHECKED_IN : AppointmentFlow::CONFIRMED;

            if ($from !== $to) {
                $appointment->update(['status' => $to, 'confirmed_at' => $appointment->confirmed_at ?? now()]
                    + ($to === AppointmentFlow::CHECKED_IN ? ['checked_in_at' => now()] : []));
                $this->log($appointment, $from, $to, $user, 'Llegó sin cita: se agendó desde el punto de venta.');
            }
        }

        $branchModel = $appointment->branch ?? $branch;

        return response()->json([
            'id'          => $appointment->id,
            'code'        => $appointment->code,
            'starts_at'   => $appointment->starts_at->copy()->setTimezone(AppointmentScheduler::timezone($branchModel, $tenant))->format('H:i'),
            'status_name' => AppointmentFlow::name($tenant, $appointment->fresh()->status),
        ], 201);
    }
}
