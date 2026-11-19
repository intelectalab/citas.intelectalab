<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\AppointmentController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\ProfessionalSchedule;
use App\Models\Service;
use App\Models\Tenant;
use App\Support\ApiKeys;
use App\Support\AppointmentFlow;
use App\Support\AppointmentScheduler;
use App\Support\Search;
use App\Support\Webhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class PublicApiController extends Controller
{
    private const RELATIONS = ['customer', 'service', 'professional', 'branch'];

    private function tenant(Request $request): Tenant
    {
        return $request->attributes->get('api_tenant');
    }

    private function need(Request $request, string $resource, string $level): void
    {
        abort_unless(ApiKeys::allows($request->attributes->get('api_key'), $resource, $level), 403, $level === 'write'
            ? 'Esta clave de API no tiene permiso para cambiar ' . mb_strtolower(ApiKeys::RESOURCES[$resource]['label']) . '.'
            : 'Esta clave de API no tiene permiso para ver ' . mb_strtolower(ApiKeys::RESOURCES[$resource]['label']) . '.');
    }

    private function forward(Request $request, string $method, string $uri, array $data, callable $action, string $controller): JsonResponse
    {
        $sub = Request::create($uri, $method, $data);
        $sub->headers->set('Accept', 'application/json');
        $sub->attributes->set('appointment_source', 'api');
        $sub->setUserResolver(fn () => $request->user());
        $sub->server->set('REMOTE_ADDR', $request->ip());

        return $action(app($controller), $sub);
    }

    private function appointment(Request $request, Appointment $appointment): array
    {
        $data = Webhooks::appointmentData($appointment);

        if (!ApiKeys::allows($request->attributes->get('api_key'), 'customers', 'read') && $data['customer']) {
            $data['customer'] = ['id' => $data['customer']['id'], 'first_name' => $data['customer']['first_name'], 'last_name' => $data['customer']['last_name']];
        }

        unset($data['previous_status']);

        return $data;
    }

    private function page(Request $request, $query, callable $map): JsonResponse
    {
        $perPage = min(100, max(1, (int) $request->query('per_page', 25)));
        $page = $query->paginate($perPage);

        return response()->json([
            'data' => collect($page->items())->map($map)->values(),
            'meta' => ['page' => $page->currentPage(), 'per_page' => $page->perPage(), 'total' => $page->total(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function services(Request $request)
    {
        $this->need($request, 'services', 'read');
        $tenant = $this->tenant($request);
        $assigned = DB::table('service_professionals')->where('tenant_id', $tenant->id)->get()->groupBy('service_id');

        return response()->json(['data' => Service::where('tenant_id', $tenant->id)->with(['status', 'category:id,name'])->orderBy('name')->get()
            ->reject(fn (Service $s) => $s->status?->slug === 'inactive')
            ->map(fn (Service $s) => [
                'id'               => $s->id,
                'name'             => $s->name,
                'category'         => $s->category?->name,
                'duration_minutes' => (int) $s->duration_minutes,
                'price'            => round((float) $s->price, 2),
                'currency'         => $s->currency ?? null,
                'kind'             => $s->kind,
                'is_virtual'       => (bool) $s->is_virtual,
                'online_booking'   => (bool) $s->online_booking,
                'branch_ids'       => $s->branch_ids,
                'professional_ids' => ($assigned[$s->id] ?? collect())->pluck('professional_id')->values(),
            ])->values()]);
    }

    public function branches(Request $request)
    {
        $this->need($request, 'services', 'read');

        return response()->json(['data' => Branch::where('tenant_id', $this->tenant($request)->id)->where('status', 'active')->orderBy('name')->get()
            ->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name, 'address' => $b->address, 'timezone' => AppointmentScheduler::timezone($b, $this->tenant($request))])]);
    }

    public function professionals(Request $request)
    {
        $this->need($request, 'services', 'read');
        $tenant = $this->tenant($request);
        $worksAt = ProfessionalSchedule::where('tenant_id', $tenant->id)->get()->groupBy('professional_id');

        return response()->json(['data' => Professional::where('tenant_id', $tenant->id)->with('status')->orderBy('display_name')->get()
            ->reject(fn (Professional $p) => $p->status?->slug === 'inactive')
            ->map(fn (Professional $p) => [
                'id'         => $p->id,
                'name'       => $p->display_name,
                'branch_ids' => ($worksAt[$p->id] ?? collect())->pluck('branch_id')->unique()->values(),
            ])->values()]);
    }

    public function availability(Request $request)
    {
        $this->need($request, 'availability', 'read');

        return $this->forward($request, 'GET', '/api/appointments/availability', $request->only(['service_id', 'branch_id', 'date', 'professional_id']),
            fn (AppointmentController $c, Request $sub) => $c->availability($sub), AppointmentController::class);
    }

    public function appointments(Request $request)
    {
        $this->need($request, 'appointments', 'read');
        $tenant = $this->tenant($request);

        $validated = $request->validate([
            'from'            => 'nullable|date_format:Y-m-d',
            'to'              => 'nullable|date_format:Y-m-d|after_or_equal:from',
            'status'          => ['nullable', Rule::in(AppointmentFlow::keys())],
            'branch_id'       => 'nullable|uuid',
            'professional_id' => 'nullable|uuid',
            'customer_id'     => 'nullable|uuid',
            'per_page'        => 'nullable|integer|min:1|max:100',
        ]);

        $zone = $tenant->zone();
        $from = Carbon::createFromFormat('Y-m-d', $validated['from'] ?? now($zone)->toDateString(), $zone)->startOfDay();
        $to = Carbon::createFromFormat('Y-m-d', $validated['to'] ?? $from->copy()->addDays(30)->toDateString(), $zone)->endOfDay();
        abort_if($from->diffInDays($to) > 93, 422, 'Pide como máximo 93 días por consulta.');

        $query = Appointment::where('tenant_id', $tenant->id)
            ->where('starts_at', '>=', $from->toIso8601String())
            ->where('starts_at', '<=', $to->toIso8601String())
            ->when($validated['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($validated['branch_id'] ?? null, fn ($q, $v) => $q->where('branch_id', $v))
            ->when($validated['professional_id'] ?? null, fn ($q, $v) => $q->where('professional_id', $v))
            ->when($validated['customer_id'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->with(self::RELATIONS)
            ->orderBy('starts_at');

        return $this->page($request, $query, fn (Appointment $a) => $this->appointment($request, $a));
    }

    public function showAppointment(Request $request, string $appointment)
    {
        $this->need($request, 'appointments', 'read');
        $found = Appointment::where('tenant_id', $this->tenant($request)->id)->with(self::RELATIONS)->findOrFail($appointment);

        return response()->json(['data' => $this->appointment($request, $found)]);
    }

    public function storeAppointment(Request $request)
    {
        $this->need($request, 'appointments', 'write');
        $data = $request->only(['customer_id', 'service_id', 'professional_id', 'branch_id', 'starts_at', 'notes', 'video_url', 'request_key']);

        $response = $this->forward($request, 'POST', '/api/appointments', $data, fn (AppointmentController $c, Request $sub) => $c->store($sub), AppointmentController::class);

        if ($response->getStatusCode() >= 300) {
            return $response;
        }

        $created = Appointment::where('tenant_id', $this->tenant($request)->id)->with(self::RELATIONS)->findOrFail($response->getData(true)['id']);

        return response()->json(['data' => $this->appointment($request, $created)], $response->getStatusCode());
    }

    public function cancelAppointment(Request $request, string $appointment)
    {
        $this->need($request, 'appointments', 'write');
        $found = Appointment::where('tenant_id', $this->tenant($request)->id)->findOrFail($appointment);

        $validated = $request->validate([
            'reason'      => 'nullable|string|max:255',
            'by_customer' => 'nullable|boolean',
        ]);

        if ($found->status === AppointmentFlow::CANCELLED) {
            return response()->json(['data' => $this->appointment($request, $found->load(self::RELATIONS))]);
        }

        $response = $this->forward($request, 'POST', "/api/appointments/{$found->id}/status", ['status' => AppointmentFlow::CANCELLED, 'reason' => $validated['reason'] ?? null, 'by_customer' => (bool) ($validated['by_customer'] ?? false)],
            fn (AppointmentController $c, Request $sub) => $c->changeStatus($sub, $found->id), AppointmentController::class);

        if ($response->getStatusCode() >= 300) {
            return $response;
        }

        return response()->json(['data' => $this->appointment($request, $found->fresh(self::RELATIONS))]);
    }

    public function customers(Request $request)
    {
        $this->need($request, 'customers', 'read');
        $tenant = $this->tenant($request);
        $search = trim((string) $request->query('search', ''));

        $query = Customer::where('tenant_id', $tenant->id)
            ->when($search !== '', fn ($q) => $q->where(fn ($q) => Search::orContains(Search::orContains(Search::orContains(Search::contains($q, "coalesce(first_name, '') || ' ' || coalesce(last_name, '')", $search), "coalesce(email, '')", $search), "coalesce(phone, '')", $search), "coalesce(document_number, '')", $search)))
            ->when($request->query('email'), fn ($q, $email) => $q->whereRaw('lower(email) = ?', [mb_strtolower((string) $email)]))
            ->orderBy('first_name')->orderBy('last_name');

        return $this->page($request, $query, fn (Customer $c) => Webhooks::customerData($c));
    }

    public function showCustomer(Request $request, string $customer)
    {
        $this->need($request, 'customers', 'read');

        return response()->json(['data' => Webhooks::customerData(Customer::where('tenant_id', $this->tenant($request)->id)->findOrFail($customer))]);
    }

    public function storeCustomer(Request $request)
    {
        $this->need($request, 'customers', 'write');
        $data = $request->only(['first_name', 'last_name', 'email', 'phone', 'document_number', 'type_document_id', 'birth_date', 'address']);

        $response = $this->forward($request, 'POST', '/api/customers', $data, fn (CustomerController $c, Request $sub) => $c->store($sub), CustomerController::class);

        if ($response->getStatusCode() >= 300) {
            return $response;
        }

        $id = $response->getData(true)['customer']['id'] ?? null;

        return response()->json(['data' => Webhooks::customerData(Customer::where('tenant_id', $this->tenant($request)->id)->findOrFail($id))], 201);
    }
}
