<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerDependent;
use App\Models\Professional;
use App\Models\Recall;
use App\Models\Service;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AppointmentFlow;
use App\Support\AppointmentScheduler;
use App\Support\Search;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Controles y seguimiento: a quién hay que volver a llamar y para qué. Desde
 * aquí se listan, se registran a mano, se marcan como avisados y se cierran.
 * Convertirlos en cita lo hace la agenda (POST /appointments con recall_id).
 */
class RecallController extends Controller
{
    private const RELATIONS = [
        'customer:id,code,first_name,last_name,phone,email,deleted_at',
        'dependent:id,name,species',
        'service:id,name',
        'professional:id,display_name',
        'appointment:id,code,starts_at,status,branch_id',
        'appointment.branch:id,timezone',
        'sourceAppointment:id,code',
    ];

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 403);

        return $tenant;
    }

    /**
     * Un usuario con rol de profesional (sin ser administrador) solo ve los
     * controles de sus propios clientes atendidos.
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
            'customer_id'     => 'nullable|uuid',
            'service_id'      => 'nullable|uuid',
            'professional_id' => 'nullable|uuid',
            'search'          => 'nullable|string|max:100',
        ]);

        $view = $validated['view'] ?? 'open';
        $statuses = match ($view) {
            'booked' => [Recall::BOOKED],
            'closed' => Recall::CLOSED,
            default  => Recall::OPEN,
        };

        $recalls = Recall::where('tenant_id', $tenant->id)
            ->whereIn('status', $statuses)
            // Un cliente eliminado ya no se sigue.
            ->whereHas('customer', fn ($q) => $q->whereNull('deleted_at'))
            ->when($own ?? ($validated['professional_id'] ?? null), fn ($q, $id) => $q->where('professional_id', $id))
            ->when($validated['customer_id'] ?? null, fn ($q, $id) => $q->where('customer_id', $id))
            ->when($validated['service_id'] ?? null, fn ($q, $id) => $q->where('service_id', $id))
            ->when($validated['search'] ?? null, function ($q, $search) {
                $q->where(function ($q) use ($search) {
                    Search::contains($q, 'label', $search);
                    $q->orWhereHas('customer', function ($q) use ($search) {
                        $q->where(function ($q) use ($search) {
                            Search::contains($q, "coalesce(first_name, '') || ' ' || coalesce(last_name, '')", $search);
                            foreach (['document_number', 'phone', 'code'] as $column) {
                                Search::orContains($q, $column, $search);
                            }
                        });
                    });
                });
            })
            ->with(self::RELATIONS)
            // Lo cerrado, de lo más reciente a lo más antiguo; lo demás, lo más urgente primero.
            ->when($view === 'closed', fn ($q) => $q->orderByDesc('updated_at'), fn ($q) => $q->orderBy('due_date'))
            ->limit(500)
            ->get();

        $today = $this->today($tenant);

        return response()->json($recalls->map(fn (Recall $recall) => $this->present($recall, $tenant, $today)));
    }

    /**
     * Control registrado a mano por el personal.
     */
    public function store(Request $request)
    {
        $tenant = $this->tenant($request);
        $own = $this->ownProfessionalId($request->user());

        $validated = $request->validate(['customer_id' => 'required|uuid'] + $this->rules(), $this->messages());

        $customer = Customer::where('tenant_id', $tenant->id)->findOrFail($validated['customer_id']);
        $links = $this->links($tenant, $customer, $validated, $own);

        $recall = Recall::create($links + [
            'tenant_id'   => $tenant->id,
            'customer_id' => $customer->id,
            'label'       => trim($validated['label']),
            'due_date'    => $validated['due_date'],
            'notes'       => $validated['notes'] ?? null,
            'status'      => Recall::PENDING,
            'created_by'  => $request->user()->id,
        ]);

        return response()->json($this->present($recall->fresh(self::RELATIONS), $tenant, $this->today($tenant)), 201);
    }

    /**
     * Cambiar la fecha, el servicio o las notas de un control que sigue abierto.
     */
    public function update(Request $request, string $recall)
    {
        $tenant = $this->tenant($request);
        $own = $this->ownProfessionalId($request->user());
        $recall = $this->find($tenant, $recall, $own);

        if (!$recall->isOpen()) {
            return response()->json(['message' => 'Este control ya no se puede cambiar: está ' . mb_strtolower(Recall::STATUSES[$recall->status]) . '.', 'code' => 'CLOSED'], 422);
        }

        $validated = $request->validate($this->rules(), $this->messages());
        $links = $this->links($tenant, $recall->customer, $validated, $own);

        $recall->update($links + [
            'label'    => trim($validated['label']),
            'due_date' => $validated['due_date'],
            'notes'    => $validated['notes'] ?? null,
        ]);

        return response()->json($this->present($recall->fresh(self::RELATIONS), $tenant, $this->today($tenant)));
    }

    /**
     * Avisado, por agendar, realizado o descartado. "Agendado" solo lo pone
     * una cita, y mientras esa cita siga en pie el control no se cambia aquí.
     */
    public function changeStatus(Request $request, string $recall)
    {
        $tenant = $this->tenant($request);
        $recall = $this->find($tenant, $recall, $this->ownProfessionalId($request->user()));

        $validated = $request->validate([
            'status' => ['required', Rule::in([Recall::PENDING, Recall::NOTIFIED, Recall::DONE, Recall::CANCELLED])],
            'reason' => 'nullable|string|max:255',
        ]);

        $to = $validated['status'];

        if ($recall->status === Recall::BOOKED) {
            return response()->json([
                'message' => 'Este control ya tiene una cita. Para cambiarlo, atiende o cancela la cita ' . ($recall->appointment?->code ?? '') . '.',
                'code'    => 'BOOKED',
            ], 422);
        }

        if ($recall->status === $to) {
            return response()->json($this->present($recall, $tenant, $this->today($tenant)));
        }

        $recall->update([
            'status'        => $to,
            'notified_at'   => match ($to) {
                Recall::NOTIFIED => now(),
                Recall::PENDING  => null,
                default          => $recall->notified_at,
            },
            'closed_reason' => in_array($to, Recall::CLOSED, true) ? (trim((string) ($validated['reason'] ?? '')) ?: null) : null,
        ]);

        return response()->json($this->present($recall->fresh(self::RELATIONS), $tenant, $this->today($tenant)));
    }

    private function find(Tenant $tenant, string $id, ?string $own): Recall
    {
        $recall = Recall::where('tenant_id', $tenant->id)->with(self::RELATIONS)->findOrFail($id);

        abort_if($own && $recall->professional_id !== $own, 403, 'Este control es de otro profesional.');

        return $recall;
    }

    private function rules(): array
    {
        return [
            'label'                 => 'required|string|max:150',
            'due_date'              => 'required|date_format:Y-m-d',
            'customer_dependent_id' => 'nullable|uuid',
            'service_id'            => 'nullable|uuid',
            'professional_id'       => 'nullable|uuid',
            'notes'                 => 'nullable|string|max:1000',
        ];
    }

    private function messages(): array
    {
        return [
            'customer_id.required' => 'Elige a quién hay que hacerle seguimiento.',
            'label.required'       => 'Escribe para qué es el control.',
            'due_date.required'    => 'Indica para cuándo es.',
            'due_date.date_format' => 'La fecha no es válida.',
        ];
    }

    /**
     * Servicio, profesional y dependiente del control, ya comprobados contra la empresa.
     */
    private function links(Tenant $tenant, Customer $customer, array $validated, ?string $own): array
    {
        $serviceId = $validated['service_id'] ?? null;
        $professionalId = $own ?? ($validated['professional_id'] ?? null);
        $dependentId = $validated['customer_dependent_id'] ?? null;

        if ($serviceId) {
            Service::where('tenant_id', $tenant->id)->findOrFail($serviceId);
        }

        if ($professionalId) {
            // Un profesional sin ficha de profesional no puede asignarse controles.
            abort_if($professionalId === 'none', 403, 'Tu usuario no está vinculado a un profesional.');
            Professional::where('tenant_id', $tenant->id)->findOrFail($professionalId);
        }

        if ($dependentId) {
            if (!$tenant->dependents()['enabled']) {
                $dependentId = null;
            } elseif (!CustomerDependent::where('customer_id', $customer->id)->whereKey($dependentId)->exists()) {
                throw ValidationException::withMessages(['customer_dependent_id' => 'No pertenece a este cliente.']);
            }
        }

        return [
            'service_id'            => $serviceId,
            'professional_id'       => $professionalId,
            'customer_dependent_id' => $dependentId,
        ];
    }

    private function present(Recall $recall, Tenant $tenant, Carbon $today): array
    {
        $customer = $recall->customer;
        $appointment = $recall->appointment;
        $due = Carbon::parse($recall->due_date->format('Y-m-d'), $today->getTimezone());

        return [
            'id'            => $recall->id,
            'label'         => $recall->label,
            'due_date'      => $recall->due_date->format('Y-m-d'),
            // Días que faltan; negativo si ya venció.
            'days_left'     => (int) round($today->diffInDays($due, false)),
            'status'        => $recall->status,
            'status_name'   => Recall::STATUSES[$recall->status] ?? $recall->status,
            'notified_at'   => $recall->notified_at?->copy()->setTimezone($today->getTimezone())->format('Y-m-d\TH:i'),
            'notes'         => $recall->notes,
            'closed_reason' => $recall->closed_reason,
            // De dónde salió: la ficha de una atención, el servicio atendido o el personal.
            'origin'        => $recall->care_record_id ? 'record' : ($recall->source_appointment_id ? 'service' : 'manual'),
            'source_code'   => $recall->sourceAppointment?->code,
            'customer'      => $customer ? [
                'id'    => $customer->id,
                'code'  => $customer->code,
                'name'  => trim("{$customer->first_name} {$customer->last_name}"),
                'first_name' => $customer->first_name,
                'last_name'  => $customer->last_name,
                'phone' => $customer->phone,
                'email' => $customer->email,
            ] : null,
            'dependent'     => $recall->dependent ? [
                'id'      => $recall->dependent->id,
                'name'    => $recall->dependent->name,
                'species' => $recall->dependent->species,
            ] : null,
            'service'       => $recall->service ? ['id' => $recall->service->id, 'name' => $recall->service->name] : null,
            'professional'  => $recall->professional ? ['id' => $recall->professional->id, 'display_name' => $recall->professional->display_name] : null,
            // La cita en la que se convirtió.
            'appointment'   => $appointment ? [
                'id'          => $appointment->id,
                'code'        => $appointment->code,
                'status'      => $appointment->status,
                'status_name' => AppointmentFlow::name($tenant, $appointment->status),
                'starts_at'   => $appointment->starts_at->copy()
                    ->setTimezone(AppointmentScheduler::timezone($appointment->branch ?? new \App\Models\Branch(), $tenant))
                    ->format('Y-m-d\TH:i'),
            ] : null,
        ];
    }
}
