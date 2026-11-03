<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\AuditEvent;
use App\Models\CareRecord;
use App\Models\CareRecordAddendum;
use App\Models\Professional;
use App\Models\RecordTemplate;
use App\Models\Tenant;
use App\Models\User;
use App\Support\AppointmentFlow;
use App\Support\Audit;
use App\Support\RecordFields;
use App\Support\Search;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use App\Support\Access;

class CareRecordController extends Controller
{
    private const LOCAL_FORMAT = 'Y-m-d\TH:i';
    private const RELATIONS = ['customer', 'dependent', 'professional', 'author', 'appointment.service', 'addenda.author'];

    private const EMERGENCY_ACTION = 'care_record.emergency_access';

    private const EMERGENCY_MINUTES = 30;

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 403);

        return $tenant->loadMissing('settings');
    }

    private function ownProfessionalId(User $user): ?string
    {
        if ($user->isTenantAdmin() || !$user->roles()->where('slug', 'professional')->exists()) {
            return null;
        }

        return Professional::where('tenant_id', $user->tenant_id)->where('user_id', $user->id)->value('id') ?? 'none';
    }

    private function appointment(Request $request, string $id): Appointment
    {
        $appointment = Appointment::where('tenant_id', $this->tenant($request)->id)
            ->with(['customer', 'dependent', 'service', 'professional', 'branch'])
            ->findOrFail($id);

        $own = $this->ownProfessionalId($request->user());
        abort_if($own && $appointment->professional_id !== $own, 403, 'Solo puedes ver las fichas de tus propias citas.');
        Access::assertBranch($request->user(), $appointment->branch_id);

        return $appointment;
    }

    private function templateFor(Appointment $appointment): ?RecordTemplate
    {
        return RecordTemplate::where('tenant_id', $appointment->tenant_id)
            ->where('status', 'active')
            ->whereHas('services', fn ($q) => $q->where('services.id', $appointment->service_id))
            ->first();
    }

    private function canRead(CareRecord $record, User $user): bool
    {
        if (!$record->is_private || $record->created_by === $user->id) {
            return true;
        }

        return AuditEvent::where('entity_id', $record->id)
            ->where('action', self::EMERGENCY_ACTION)
            ->where('actor_user_id', $user->id)
            ->where('created_at', '>', now()->subMinutes(self::EMERGENCY_MINUTES))
            ->exists();
    }

    private function isOwn(CareRecord $record, User $user): bool
    {
        return !$record->is_private || $record->created_by === $user->id;
    }

    public function emergencyAccess(Request $request, string $careRecord)
    {
        $tenant = $this->tenant($request);
        $user = $request->user();

        abort_unless($user->allows('records.emergency'), 403, 'Tu cuenta no tiene permiso para pedir acceso de emergencia a una ficha privada.');

        $record = CareRecord::where('tenant_id', $tenant->id)->with(self::RELATIONS)->findOrFail($careRecord);

        $validated = $request->validate(
            ['reason' => 'required|string|min:10|max:500'],
            ['reason.required' => 'Escribe el motivo del acceso.', 'reason.min' => 'Explica el motivo con al menos 10 caracteres.'],
        );

        if (!$record->is_private || $record->created_by === $user->id) {
            throw ValidationException::withMessages(['reason' => 'Esta ficha no necesita acceso de emergencia.']);
        }

        AuditEvent::create([
            'tenant_id'     => $tenant->id,
            'actor_user_id' => $user->id,
            'action'        => self::EMERGENCY_ACTION,
            'entity_type'   => 'care_record',
            'entity_id'     => $record->id,
            'ip_address'    => $request->ip(),
            'user_agent'    => substr((string) $request->userAgent(), 0, 500),
            'after_data'    => [
                'reason'      => trim($validated['reason']),
                'customer_id' => $record->customer_id,
                'author_id'   => $record->created_by,
            ],
        ]);

        \App\Support\Notifier::emergencyAccess($tenant, $record, $user, trim($validated['reason']));

        return response()->json($this->present($record, $tenant, $user));
    }

    public function pdf(Request $request, string $careRecord)
    {
        $tenant = $this->tenant($request);
        $user = $request->user();
        $record = $this->find($request, $careRecord);

        abort_unless($this->canRead($record, $user), 403, 'Esta ficha es privada: solo la ve quien la escribió.');

        $data = $this->present($record, $tenant, $user);
        $tenant->loadMissing(['company', 'businessType']);

        $document = \App\Support\Documents::forPdf($tenant);
        $professional = $record->professional;
        $signed = $data['status'] !== CareRecord::DRAFT;

        $html = view('records.pdf', [
            'record'  => $data,
            'values'  => (array) $data['data'],
            'company' => $tenant->name,
            'document' => $document,
            'signer'  => [
                'image'   => $signed && $professional ? \App\Support\Documents::dataUri(\App\Support\Documents::professionalSignaturePath($professional)) : null,
                'license' => $professional?->license_number,
            ],
            'color'   => $document['color'],
            'client'  => $tenant->vocabulary()['client'],
            'pro'     => $tenant->vocabulary()['pro'],
            'dependentLabel' => $tenant->vocabulary()['dependent'],
            'printedAt' => now($this->timezone($tenant))->format('d/m/Y H:i'),
            'printedBy' => \App\Support\Person::name($user),
        ])->render();

        $name = 'Ficha ' . ($data['appointment']['code'] ?? '') . ' ' . ($data['customer']['name'] ?? '');
        $name = trim(preg_replace('/[^\pL\pN \-]+/u', '', $name)) ?: 'Ficha';

        return response(\App\Support\Documents::pdf($html), 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . \Illuminate\Support\Str::ascii($name) . '.pdf"',
        ]);
    }

    public function forAppointment(Request $request, string $appointment)
    {
        return response()->json($this->context($request, $this->appointment($request, $appointment)));
    }

    public function refreshTemplate(Request $request, string $appointment)
    {
        $user = $request->user();
        $appointment = $this->appointment($request, $appointment);

        $validated = $request->validate(['data' => 'sometimes|array']);

        DB::transaction(function () use ($user, $appointment, $validated) {
            $record = CareRecord::where('appointment_id', $appointment->id)->lockForUpdate()->first();
            abort_unless($record, 404, 'Esta cita todavía no tiene ficha.');
            abort_unless($this->isOwn($record, $user), 403, 'Esta ficha es privada: solo la cambia quien la escribió.');

            if ($record->status !== CareRecord::DRAFT) {
                throw ValidationException::withMessages(['data' => 'Una ficha firmada conserva los campos con que se llenó. Puedes agregarle una nota.']);
            }

            $template = $this->templateFor($appointment);

            if (!$template) {
                throw ValidationException::withMessages(['data' => 'Este servicio ya no tiene una ficha asignada.']);
            }

            $types = collect($record->template_snapshot ?? [])->pluck('type', 'key');
            $kept = collect($template->fields ?? [])
                ->filter(fn ($field) => $types->get($field['key'] ?? '') === ($field['type'] ?? null))
                ->pluck('key')
                ->all();
            [$values] = RecordFields::values($template->fields ?? [], array_intersect_key($validated['data'] ?? $record->data ?? [], array_flip($kept)));

            if ($record->consent_text !== $template->consent_text) {
                $record->consent_accepted = false;
                $record->consent_accepted_at = null;
            }

            $record->record_template_id = $template->id;
            $record->template_snapshot = $template->fields ?? [];
            $record->template_name = $template->name;
            $record->consent_text = $template->consent_text;
            $record->data = $values;

            if ($this->storesRequireConsent($record)) {
                $record->require_consent = (bool) $template->require_consent;
            }

            $record->save();
        });

        return response()->json($this->context($request, $appointment));
    }

    private function templateUpdate(CareRecord $record, Appointment $appointment, User $user): ?array
    {
        if ($record->status !== CareRecord::DRAFT || !$this->isOwn($record, $user)) {
            return null;
        }

        $template = $this->templateFor($appointment);

        if (!$template) {
            return null;
        }

        $same = ($record->template_snapshot ?? []) == ($template->fields ?? [])
            && $record->template_name === $template->name
            && $record->consent_text === $template->consent_text
            && (!$this->storesRequireConsent($record) || (bool) $record->require_consent === (bool) $template->require_consent);

        return $same ? null : [
            'name'            => $template->name,
            'fields'          => $template->fields ?? [],
            'consent_text'    => $template->consent_text,
            'require_consent' => (bool) $template->require_consent,
        ];
    }

    private function storesRequireConsent(CareRecord $record): bool
    {
        return array_key_exists('require_consent', $record->getAttributes());
    }

    private function context(Request $request, Appointment $appointment): array
    {
        $tenant = $this->tenant($request);
        $user = $request->user();
        $record = CareRecord::where('appointment_id', $appointment->id)->with(self::RELATIONS)->first();
        $template = $record ? null : $this->templateFor($appointment);

        $previous = CareRecord::where('tenant_id', $tenant->id)
            ->where('customer_id', $appointment->customer_id)
            ->when($appointment->customer_dependent_id, fn ($q, $id) => $q->where('customer_dependent_id', $id))
            ->when($record, fn ($q) => $q->where('id', '!=', $record->id))
            ->where('status', '!=', CareRecord::DRAFT)
            ->with(self::RELATIONS)
            ->orderByDesc('signed_at')
            ->limit(5)
            ->get();

        return [
            'appointment' => [
                'id'           => $appointment->id,
                'code'         => $appointment->code,
                'status'       => $appointment->status,
                'starts_at'    => $appointment->starts_at->copy()->setTimezone($this->timezone($tenant))->format(self::LOCAL_FORMAT),
                'customer'     => $appointment->customer ? \App\Support\Person::name($appointment->customer) : null,
                'dependent'    => $appointment->dependent?->name,
                'service'      => $appointment->service?->name,
                'professional' => $appointment->professional?->display_name,
            ],
            'uses_record' => (bool) ($record || $template),
            'record'      => $record ? $this->present($record, $tenant, $user) : null,
            'template'    => $template ? [
                'name'            => $template->name,
                'fields'          => $template->fields ?? [],
                'consent_text'    => $template->consent_text,
                'require_consent' => $template->require_consent,
            ] : null,
            'private_by_default'   => (bool) AppointmentFlow::rule($tenant, 'records.private_to_author'),
            'required_to_complete' => (bool) AppointmentFlow::rule($tenant, 'records.require_on_complete'),
            'lock_hours'           => (int) AppointmentFlow::rule($tenant, 'records.lock_hours'),
            'previous'             => $previous->map(fn (CareRecord $r) => $this->present($r, $tenant, $user)),
            'template_update'      => $record ? $this->templateUpdate($record, $appointment, $user) : null,
        ];
    }

    public function save(Request $request, string $appointment)
    {
        $tenant = $this->tenant($request);
        $user = $request->user();
        $appointment = $this->appointment($request, $appointment);

        $validated = $request->validate([
            'data'             => 'present|array',
            'consent_accepted' => 'boolean',
            'sign'             => 'boolean',
        ]);

        if (in_array($appointment->status, AppointmentFlow::RELEASED, true)) {
            throw ValidationException::withMessages(['data' => 'Una cita cancelada o a la que no asistieron no lleva ficha.']);
        }

        $sign = !empty($validated['sign']);

        $auditAction = null;

        $record = DB::transaction(function () use ($tenant, $user, $appointment, $validated, $sign, &$auditAction) {
            $record = CareRecord::where('appointment_id', $appointment->id)->lockForUpdate()->first();

            if (!$record) {
                $template = $this->templateFor($appointment);

                if (!$template) {
                    throw ValidationException::withMessages(['data' => 'Este servicio no tiene una ficha asignada.']);
                }

                $record = new CareRecord([
                    'tenant_id'             => $tenant->id,
                    'branch_id'             => $appointment->branch_id,
                    'record_template_id'    => $template->id,
                    'template_snapshot'     => $template->fields ?? [],
                    'template_name'         => $template->name,
                    'consent_text'          => $template->consent_text,
                    'customer_id'           => $appointment->customer_id,
                    'customer_dependent_id' => $appointment->customer_dependent_id,
                    'appointment_id'        => $appointment->id,
                    'professional_id'       => $appointment->professional_id,
                    'is_private'            => (bool) AppointmentFlow::rule($tenant, 'records.private_to_author'),
                    'status'                => CareRecord::DRAFT,
                    'created_by'            => $user->id,
                ]);
                $requireConsent = (bool) $template->require_consent;

                if (Schema::hasColumn('care_records', 'require_consent')) {
                    $record->require_consent = $requireConsent;
                }
            } else {
                abort_unless($this->isOwn($record, $user), 403, 'Esta ficha es privada: solo la cambia quien la escribió.');

                if ($record->currentStatus() === CareRecord::LOCKED) {
                    throw ValidationException::withMessages(['data' => 'La ficha ya está bloqueada. Puedes agregarle una nota.']);
                }

                $requireConsent = $this->storesRequireConsent($record)
                    ? (bool) $record->require_consent
                    : (bool) RecordTemplate::whereKey($record->record_template_id)->value('require_consent');
            }

            $strict = $sign || $record->status === CareRecord::SIGNED;
            [$values, $errors] = RecordFields::values($record->template_snapshot ?? [], $validated['data'], $strict);

            $consent = !empty($validated['consent_accepted']);

            if ($strict && $requireConsent && $record->consent_text && !$consent) {
                $errors['consent'] = 'Falta que el cliente acepte el consentimiento.';
            }

            if ($errors) {
                throw ValidationException::withMessages($errors);
            }

            $record->data = $values;

            if ($consent !== (bool) $record->consent_accepted) {
                $record->consent_accepted = $consent;
                $record->consent_accepted_at = $consent ? now() : null;
            }

            if ($record->exists && $record->status === CareRecord::SIGNED && $record->isDirty(['data', 'consent_accepted'])) {
                $auditAction = 'record.corrected';
            }

            if ($sign && $record->status === CareRecord::DRAFT) {
                $auditAction = 'record.signed';
                $record->status = CareRecord::SIGNED;
                $record->signed_at = now();
                $record->signed_by = $user->id;
                $record->locked_at = now()->addHours(max(0, (int) AppointmentFlow::rule($tenant, 'records.lock_hours')));
            }

            $record->save();

            if ($record->status === CareRecord::SIGNED) {
                $this->syncRecall($record, $appointment);
            }

            return $record;
        });

        if ($auditAction) {
            $this->audit($request, $auditAction, $record, $appointment);
        }

        return response()->json($this->present($record->fresh(self::RELATIONS), $tenant, $user));
    }

    private function audit(Request $request, string $action, CareRecord $record, ?Appointment $appointment = null): void
    {
        $record->loadMissing('customer');
        $appointment ??= $record->appointment_id ? Appointment::find($record->appointment_id) : null;

        Audit::log($request, $action, [
            'tenant'       => $record->tenant_id,
            'entity_type'  => 'care_record',
            'entity_id'    => $record->id,
            'entity_label' => \App\Support\Person::name($record->customer),
            'branch_id'    => $record->branch_id,
            'after'        => array_filter(['template_name' => $record->template_name, 'appointment_code' => $appointment?->code]),
        ]);
    }

    public function addAddendum(Request $request, string $careRecord)
    {
        $tenant = $this->tenant($request);
        $user = $request->user();
        $record = $this->find($request, $careRecord);

        abort_unless($this->isOwn($record, $user), 403, 'Esta ficha es privada: solo la cambia quien la escribió.');

        $validated = $request->validate(['note' => 'required|string|max:5000'], ['note.required' => 'Escribe la nota.']);

        if ($record->status === CareRecord::DRAFT) {
            throw ValidationException::withMessages(['note' => 'La ficha aún es un borrador: edítala directamente.']);
        }

        CareRecordAddendum::create([
            'tenant_id'      => $tenant->id,
            'care_record_id' => $record->id,
            'author_user_id' => $user->id,
            'note'           => trim($validated['note']),
        ]);
        $this->audit($request, 'record.note_added', $record);

        return response()->json($this->present($record->fresh(self::RELATIONS), $tenant, $user));
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);
        $user = $request->user();
        $timezone = $this->timezone($tenant);

        $validated = $request->validate([
            'customer_id' => 'nullable|uuid',
            'status'      => 'nullable|in:draft,signed,locked',
            'search'      => 'nullable|string|max:100',
            'from'        => 'nullable|date_format:Y-m-d',
            'to'          => 'nullable|date_format:Y-m-d|after_or_equal:from',
        ]);

        $own = $this->ownProfessionalId($user);
        $status = $validated['status'] ?? null;

        $records = CareRecord::where('tenant_id', $tenant->id)
            ->with(self::RELATIONS)
            ->when($own, fn ($q) => $q->where('professional_id', $own))
            ->when(Access::branchLimit($user), fn ($q, $id) => $q->where('branch_id', $id))
            ->when($validated['customer_id'] ?? null, fn ($q, $id) => $q->where('customer_id', $id))
            ->when($validated['from'] ?? null, fn ($q, $from) => $q->where('created_at', '>=', Carbon::parse($from, $timezone)->startOfDay()->toIso8601String()))
            ->when($validated['to'] ?? null, fn ($q, $to) => $q->where('created_at', '<', Carbon::parse($to, $timezone)->addDay()->startOfDay()->toIso8601String()))
            ->when($status === 'draft', fn ($q) => $q->where('status', CareRecord::DRAFT))
            ->when($status === 'signed', fn ($q) => $q->where('status', CareRecord::SIGNED)->where(fn ($q) => $q->whereNull('locked_at')->orWhere('locked_at', '>', now())))
            ->when($status === 'locked', fn ($q) => $q->where('status', '!=', CareRecord::DRAFT)->where('locked_at', '<=', now()))
            ->when($validated['search'] ?? null, function ($q, $search) {
                $q->whereHas('customer', function ($q) use ($search) {
                    $q->where(function ($q) use ($search) {
                        Search::contains($q, "coalesce(first_name, '') || ' ' || coalesce(last_name, '')", $search);
                        Search::orContains($q, 'document_number', $search);
                        Search::orContains($q, 'code', $search);
                    });
                });
            })
            ->orderByDesc('created_at')
            ->limit(300)
            ->get();

        return response()->json($records->map(fn (CareRecord $r) => array_diff_key($this->present($r, $tenant, $user), array_flip(['fields', 'data', 'addenda', 'consent_text']))));
    }

    public function show(Request $request, string $careRecord)
    {
        return response()->json($this->present($this->find($request, $careRecord), $this->tenant($request), $request->user()));
    }

    private function find(Request $request, string $id): CareRecord
    {
        $record = CareRecord::where('tenant_id', $this->tenant($request)->id)->with(self::RELATIONS)->findOrFail($id);

        $own = $this->ownProfessionalId($request->user());
        abort_if($own && $record->professional_id !== $own, 403, 'Solo puedes ver las fichas de tus propias citas.');
        Access::assertBranch($request->user(), $record->branch_id);

        return $record;
    }

    private function syncRecall(CareRecord $record, Appointment $appointment): void
    {
        $control = RecordFields::nextControl($record->template_snapshot ?? [], $record->data ?? []);
        $existing = DB::table('recalls')
            ->where('tenant_id', $record->tenant_id)
            ->whereIn('status', ['pending', 'notified'])
            ->where(fn ($q) => $q->where('care_record_id', $record->id)
                ->orWhere(fn ($q) => $q->whereNull('care_record_id')->where('source_appointment_id', $appointment->id)))
            ->first();

        if (!$control) {
            if ($existing && $existing->care_record_id) {
                DB::table('recalls')->where('id', $existing->id)->delete();
            }
            return;
        }

        $values = [
            'label'      => $control['label'],
            'due_date'   => $control['date'],
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('recalls')->where('id', $existing->id)->update($values + ['care_record_id' => $record->id]);
            return;
        }

        DB::table('recalls')->insert($values + [
            'tenant_id'             => $record->tenant_id,
            'customer_id'           => $record->customer_id,
            'customer_dependent_id' => $record->customer_dependent_id,
            'care_record_id'        => $record->id,
            'source_appointment_id' => $appointment->id,
            'service_id'            => $appointment->service?->recall_service_id ?? $appointment->service_id,
            'professional_id'       => $record->professional_id,
            'status'                => 'pending',
            'created_at'            => now(),
        ]);
    }

    private function emergencyAccesses(CareRecord $record, callable $local): array
    {
        return AuditEvent::where('entity_id', $record->id)
            ->where('action', self::EMERGENCY_ACTION)
            ->with('actor:id,first_name,last_name')
            ->orderBy('created_at')
            ->get()
            ->map(fn (AuditEvent $event) => [
                'by'     => $event->actor ? \App\Support\Person::name($event->actor) : null,
                'at'     => $local($event->created_at),
                'reason' => $event->after_data['reason'] ?? null,
            ])
            ->all();
    }

    private function timezone(Tenant $tenant): string
    {
        return $tenant->zone();
    }

    private function present(CareRecord $record, Tenant $tenant, User $user): array
    {
        $timezone = $this->timezone($tenant);
        $local = fn (?Carbon $date) => $date?->copy()->setTimezone($timezone)->format(self::LOCAL_FORMAT);
        $status = $record->currentStatus();
        $readable = $this->canRead($record, $user);
        $customer = $record->customer;

        return [
            'id'             => $record->id,
            'status'         => $status,
            'template_name'  => $record->template_name,
            'is_private'     => $record->is_private,
            'hidden'         => !$readable,
            'reading_by_emergency' => $readable && !$this->isOwn($record, $user),
            'can_request_access' => !$readable && $user->allows('records.emergency'),
            'emergency_accesses' => $record->is_private && $readable ? $this->emergencyAccesses($record, $local) : [],
            'can_edit'       => $readable && $status !== CareRecord::LOCKED && $this->isOwn($record, $user),
            'can_add_note'   => $readable && $status !== CareRecord::DRAFT && $this->isOwn($record, $user),
            'created_at'     => $local($record->created_at),
            'signed_at'      => $local($record->signed_at),
            'locked_at'      => $local($record->locked_at),
            'author'         => $record->author ? \App\Support\Person::name($record->author) : null,
            'professional_id' => $record->professional_id,
            'appointment_id' => $record->appointment_id,
            'appointment'    => $record->appointment ? [
                'code'      => $record->appointment->code,
                'starts_at' => $local($record->appointment->starts_at),
                'service'   => $record->appointment->service?->name,
            ] : null,
            'customer'       => $customer ? ['id' => $customer->id, 'code' => $customer->code, 'name' => \App\Support\Person::name($customer)] : null,
            'dependent'      => $record->dependent ? ['id' => $record->dependent->id, 'name' => $record->dependent->name] : null,
            'professional'   => $record->professional?->display_name,
            'consent_accepted' => $record->consent_accepted,
            'consent_text'   => $readable ? $record->consent_text : null,
            'require_consent' => (bool) $record->require_consent,
            'fields'         => $readable ? ($record->template_snapshot ?? []) : [],
            'data'           => $readable ? (object) ($record->data ?? []) : (object) [],
            'addenda'        => $readable ? $record->addenda->map(fn (CareRecordAddendum $note) => [
                'note' => $note->note,
                'by'   => $note->author ? \App\Support\Person::name($note->author) : null,
                'at'   => $local($note->created_at),
            ]) : [],
        ];
    }
}
