<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerDependent;
use App\Models\Professional;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WaitlistEntry;
use App\Support\AppointmentFlow;
use App\Support\AppointmentScheduler;
use App\Support\Search;
use App\Support\Waitlist;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use App\Support\Access;

/**
 * Lista de espera: quién quiere una cita y no encontró horario. Desde aquí se
 * anota, se le busca un horario libre y se le avisa. Convertirla en cita lo
 * hace la agenda (POST /appointments con waitlist_id).
 */
class WaitlistController extends Controller
{
    private const RELATIONS = [
        'customer:id,code,first_name,last_name,phone,email,deleted_at',
        'dependent:id,name,species',
        'service:id,name,duration_minutes,kind',
        'professional:id,display_name',
        'branch:id,name,timezone',
        'appointment:id,code,starts_at,status',
    ];

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 403);

        return $tenant;
    }

    /**
     * Un usuario con rol de profesional (sin ser administrador) ve a quienes
     * lo esperan a él o a cualquiera, y solo anota para sí mismo.
     */
    private function ownProfessionalId(User $user): ?string
    {
        if ($user->isTenantAdmin() || !$user->roles()->where('slug', 'professional')->exists()) {
            return null;
        }

        return Professional::where('tenant_id', $user->tenant_id)->where('user_id', $user->id)->value('id') ?? 'none';
    }

    private function today(Tenant $tenant): Carbon
    {
        return now($tenant->timezone ?: 'America/Lima')->startOfDay();
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);
        $own = $this->ownProfessionalId($request->user());

        $validated = $request->validate([
            'view'            => 'nullable|in:open,booked,closed',
            'branch_id'       => 'nullable|uuid',
            'service_id'      => 'nullable|uuid',
            'professional_id' => 'nullable|uuid',
            'customer_id'     => 'nullable|uuid',
            'search'          => 'nullable|string|max:100',
        ]);

        // Antes de listar, salen quienes ya no tienen fecha posible.
        Waitlist::expire($tenant);

        $view = $validated['view'] ?? 'open';
        $statuses = match ($view) {
            'booked' => [WaitlistEntry::BOOKED],
            'closed' => WaitlistEntry::CLOSED,
            default  => WaitlistEntry::OPEN,
        };

        $entries = WaitlistEntry::where('tenant_id', $tenant->id)
            ->whereIn('status', $statuses)
            ->whereHas('customer', fn ($q) => $q->whereNull('deleted_at'))
            ->when($own, fn ($q) => $q->where(fn ($q) => $q->whereNull('professional_id')->orWhere('professional_id', $own)))
            ->when($validated['professional_id'] ?? null, fn ($q, $id) => $q->where('professional_id', $id))
            ->when($validated['branch_id'] ?? null, fn ($q, $id) => $q->where('branch_id', $id))
            ->when(Access::branchLimit($request->user()), fn ($q, $id) => $q->where('branch_id', $id))
            ->when($validated['service_id'] ?? null, fn ($q, $id) => $q->where('service_id', $id))
            ->when($validated['customer_id'] ?? null, fn ($q, $id) => $q->where('customer_id', $id))
            ->when($validated['search'] ?? null, function ($q, $search) {
                $q->whereHas('customer', function ($q) use ($search) {
                    $q->where(function ($q) use ($search) {
                        Search::contains($q, "coalesce(first_name, '') || ' ' || coalesce(last_name, '')", $search);
                        foreach (['document_number', 'phone', 'code'] as $column) {
                            Search::orContains($q, $column, $search);
                        }
                    });
                });
            })
            ->with(self::RELATIONS)
            // En espera: primero los prioritarios y luego por orden de llegada.
            ->when($view === 'open', fn ($q) => $q->orderByDesc('priority')->orderBy('created_at'), fn ($q) => $q->orderByDesc('updated_at'))
            ->limit(500)
            ->get();

        $today = $this->today($tenant);

        return response()->json($entries->map(fn (WaitlistEntry $entry) => $this->present($entry, $tenant, $today)));
    }

    public function store(Request $request)
    {
        $tenant = $this->tenant($request);
        $own = $this->ownProfessionalId($request->user());

        $validated = $request->validate(['customer_id' => 'required|uuid'] + $this->rules(), $this->messages());
        $customer = Customer::where('tenant_id', $tenant->id)->findOrFail($validated['customer_id']);
        $values = $this->values($tenant, $customer, $validated, $own);
        Access::assertBranch($request->user(), $values['branch_id'], true);

        if ($values['date_to'] && $values['date_to'] < $this->today($tenant)->format('Y-m-d')) {
            throw ValidationException::withMessages(['date_to' => 'La última fecha ya pasó.']);
        }

        // La misma persona esperando lo mismo dos veces solo estorba.
        $duplicate = WaitlistEntry::where('tenant_id', $tenant->id)
            ->whereIn('status', WaitlistEntry::OPEN)
            ->where('customer_id', $customer->id)
            ->where('service_id', $values['service_id'])
            ->where('branch_id', $values['branch_id'])
            ->where(fn ($q) => $values['customer_dependent_id']
                ? $q->where('customer_dependent_id', $values['customer_dependent_id'])
                : $q->whereNull('customer_dependent_id'))
            ->exists();

        if ($duplicate) {
            return response()->json([
                'message' => 'Ya está en la lista de espera para ese servicio. Cambia su registro en lugar de anotarlo otra vez.',
                'code'    => 'DUPLICATE',
            ], 409);
        }

        $entry = WaitlistEntry::create($values + [
            'tenant_id'   => $tenant->id,
            'customer_id' => $customer->id,
            'status'      => WaitlistEntry::WAITING,
            'created_by'  => $request->user()->id,
        ]);

        return response()->json($this->present($entry->fresh(self::RELATIONS), $tenant, $this->today($tenant)), 201);
    }

    public function update(Request $request, string $entry)
    {
        $tenant = $this->tenant($request);
        $own = $this->ownProfessionalId($request->user());
        $entry = $this->find($tenant, $entry, $own);
        Access::assertBranch($request->user(), $entry->branch_id);

        if (!$entry->isOpen()) {
            return response()->json(['message' => 'Este registro ya no se puede cambiar.', 'code' => 'CLOSED'], 422);
        }

        $validated = $request->validate($this->rules(), $this->messages());
        $values = $this->values($tenant, $entry->customer, $validated, $own);
        Access::assertBranch($request->user(), $values['branch_id'], true);
        $entry->update($values);

        return response()->json($this->present($entry->fresh(self::RELATIONS), $tenant, $this->today($tenant)));
    }

    /**
     * Avisado, en espera o retirado. "Con cita" solo lo pone una cita.
     */
    public function changeStatus(Request $request, string $entry)
    {
        $tenant = $this->tenant($request);
        $entry = $this->find($tenant, $entry, $this->ownProfessionalId($request->user()));
        Access::assertBranch($request->user(), $entry->branch_id);

        $validated = $request->validate([
            'status' => ['required', Rule::in([WaitlistEntry::WAITING, WaitlistEntry::NOTIFIED, WaitlistEntry::CANCELLED])],
        ]);

        $to = $validated['status'];

        if ($entry->status === WaitlistEntry::BOOKED) {
            return response()->json([
                'message' => 'Ya tiene una cita. Para cambiarlo, cancela la cita ' . ($entry->appointment?->code ?? '') . '.',
                'code'    => 'BOOKED',
            ], 422);
        }

        // Volver a la lista con la última fecha ya pasada no tiene sentido.
        if ($to !== WaitlistEntry::CANCELLED && $entry->date_to && $entry->date_to->format('Y-m-d') < $this->today($tenant)->format('Y-m-d')) {
            return response()->json([
                'message' => 'Su última fecha ya pasó. Anótalo de nuevo con fechas vigentes.',
                'code'    => 'EXPIRED',
            ], 422);
        }

        $entry->update([
            'status'      => $to,
            'notified_at' => $to === WaitlistEntry::NOTIFIED ? now() : ($to === WaitlistEntry::WAITING ? null : $entry->notified_at),
        ]);

        return response()->json($this->present($entry->fresh(self::RELATIONS), $tenant, $this->today($tenant)));
    }

    /**
     * Horarios libres que le sirven a esta persona, para agendarla.
     */
    public function slots(Request $request, string $entry)
    {
        $tenant = $this->tenant($request);
        $entry = $this->find($tenant, $entry, $this->ownProfessionalId($request->user()));
        Access::assertBranch($request->user(), $entry->branch_id);

        if (!$entry->isOpen()) {
            return response()->json(['message' => 'Este registro ya no está en espera.', 'code' => 'CLOSED'], 422);
        }

        return response()->json(Waitlist::slots($tenant, $entry));
    }

    private function find(Tenant $tenant, string $id, ?string $own): WaitlistEntry
    {
        $entry = WaitlistEntry::where('tenant_id', $tenant->id)->with(self::RELATIONS)->findOrFail($id);

        abort_if($own && $entry->professional_id && $entry->professional_id !== $own, 403, 'Esta persona espera a otro profesional.');

        return $entry;
    }

    private function rules(): array
    {
        return [
            'service_id'            => 'required|uuid',
            'branch_id'             => 'required|uuid',
            'professional_id'       => 'nullable|uuid',
            'customer_dependent_id' => 'nullable|uuid',
            'date_from'             => 'required|date_format:Y-m-d',
            'date_to'               => 'nullable|date_format:Y-m-d|after_or_equal:date_from',
            'time_from'             => 'nullable|date_format:H:i',
            'time_to'               => 'nullable|date_format:H:i',
            'priority'              => 'nullable|boolean',
            'notes'                 => 'nullable|string|max:1000',
        ];
    }

    private function messages(): array
    {
        return [
            'customer_id.required'   => 'Elige a quién se anota.',
            'service_id.required'    => 'Elige el servicio que espera.',
            'branch_id.required'     => 'Elige la sede.',
            'date_from.required'     => 'Indica desde qué fecha le sirve.',
            'date_to.after_or_equal' => 'La última fecha no puede ser anterior a la primera.',
            'time_from.date_format'  => 'La hora no es válida.',
            'time_to.date_format'    => 'La hora no es válida.',
        ];
    }

    /**
     * Los datos del registro, ya comprobados contra la empresa.
     */
    private function values(Tenant $tenant, Customer $customer, array $validated, ?string $own): array
    {
        $service = Service::where('tenant_id', $tenant->id)->findOrFail($validated['service_id']);
        $branch = Branch::where('tenant_id', $tenant->id)->where('status', 'active')->findOrFail($validated['branch_id']);

        if ($service->kind === Service::KIND_GROUP) {
            throw ValidationException::withMessages(['service_id' => 'Las clases grupales tienen su propia lista, por cupos.']);
        }

        $professionalId = $validated['professional_id'] ?? null;

        if ($own) {
            abort_if($own === 'none', 403, 'Tu usuario no está vinculado a un profesional.');
            abort_if($professionalId && $professionalId !== $own, 403, 'Solo puedes anotar personas que te esperan a ti o a cualquiera.');
        }

        if ($professionalId) {
            Professional::where('tenant_id', $tenant->id)->findOrFail($professionalId);
        }

        $dependents = $tenant->dependents();
        $dependentId = $dependents['enabled'] ? ($validated['customer_dependent_id'] ?? null) : null;

        if ($dependentId && !CustomerDependent::where('customer_id', $customer->id)->whereKey($dependentId)->exists()) {
            throw ValidationException::withMessages(['customer_dependent_id' => 'No pertenece a este cliente.']);
        }

        if ($dependents['enabled'] && $dependents['required'] && !$dependentId) {
            throw ValidationException::withMessages(['customer_dependent_id' => 'Indica para quién es la cita.']);
        }

        $timeFrom = $validated['time_from'] ?? null;
        $timeTo = $validated['time_to'] ?? null;

        if ($timeFrom && $timeTo && $timeTo <= $timeFrom) {
            throw ValidationException::withMessages(['time_to' => 'La hora final debe ser posterior a la inicial.']);
        }

        return [
            'service_id'            => $service->id,
            'branch_id'             => $branch->id,
            'professional_id'       => $professionalId,
            'customer_dependent_id' => $dependentId,
            'date_from'             => $validated['date_from'],
            'date_to'               => $validated['date_to'] ?? null,
            'time_from'             => $timeFrom,
            'time_to'               => $timeTo,
            'priority'              => !empty($validated['priority']) ? 1 : 0,
            'notes'                 => $validated['notes'] ?? null,
        ];
    }

    private function present(WaitlistEntry $entry, Tenant $tenant, Carbon $today): array
    {
        $customer = $entry->customer;
        $appointment = $entry->appointment;
        $timezone = $today->getTimezone();
        $dateTo = $entry->date_to ? Carbon::parse($entry->date_to->format('Y-m-d'), $timezone) : null;

        return [
            'id'           => $entry->id,
            'status'       => $entry->status,
            'status_name'  => WaitlistEntry::STATUSES[$entry->status] ?? $entry->status,
            'priority'     => $entry->priority > 0,
            'date_from'    => $entry->date_from->format('Y-m-d'),
            'date_to'      => $entry->date_to?->format('Y-m-d'),
            'time_from'    => $entry->time_from ? substr($entry->time_from, 0, 5) : null,
            'time_to'      => $entry->time_to ? substr($entry->time_to, 0, 5) : null,
            'notes'        => $entry->notes,
            'notified_at'  => $entry->notified_at?->copy()->setTimezone($timezone)->format('Y-m-d\TH:i'),
            'created_at'   => Carbon::parse($entry->created_at)->setTimezone($timezone)->format('Y-m-d\TH:i'),
            'days_waiting' => (int) Carbon::parse($entry->created_at)->setTimezone($timezone)->startOfDay()->diffInDays($today),
            // Días que le quedan para recibir un horario; null si no puso última fecha.
            'days_left'    => $dateTo ? (int) round($today->diffInDays($dateTo, false)) : null,
            'customer'     => $customer ? [
                'id'         => $customer->id,
                'code'       => $customer->code,
                'name'       => trim("{$customer->first_name} {$customer->last_name}"),
                'phone'      => $customer->phone,
                'email'      => $customer->email,
            ] : null,
            'dependent'    => $entry->dependent ? ['id' => $entry->dependent->id, 'name' => $entry->dependent->name, 'species' => $entry->dependent->species] : null,
            'service'      => $entry->service ? ['id' => $entry->service->id, 'name' => $entry->service->name, 'duration_minutes' => $entry->service->duration_minutes] : null,
            'professional' => $entry->professional ? ['id' => $entry->professional->id, 'display_name' => $entry->professional->display_name] : null,
            'branch'       => $entry->branch ? ['id' => $entry->branch->id, 'name' => $entry->branch->name] : null,
            'appointment'  => $appointment ? [
                'id'          => $appointment->id,
                'code'        => $appointment->code,
                'status'      => $appointment->status,
                'status_name' => AppointmentFlow::name($tenant, $appointment->status),
                'starts_at'   => $appointment->starts_at->copy()
                    ->setTimezone(AppointmentScheduler::timezone($entry->branch ?? new Branch(), $tenant))
                    ->format('Y-m-d\TH:i'),
            ] : null,
        ];
    }
}
