<?php

namespace App\Support\Assistant;

use App\Http\Controllers\Api\ChatBookingController;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Professional;
use App\Models\Service;
use App\Models\Tenant;
use App\Support\AppointmentFlow;
use App\Support\AppointmentScheduler;
use App\Support\Audit;
use App\Support\BranchHours;
use App\Support\Currency;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Tools
{
    public const WEEKDAYS = [0 => 'domingo', 1 => 'lunes', 2 => 'martes', 3 => 'miércoles', 4 => 'jueves', 5 => 'viernes', 6 => 'sábado'];

    public array $done = [];

    public ?string $handoff = null;

    public bool $simulate = false;

    public function __construct(public Tenant $tenant, public ?Customer $customer, public object $conversation)
    {
    }

    public function canBook(): bool
    {
        return (bool) AppointmentFlow::rule($this->tenant, 'assistant.can_book') && (bool) AppointmentFlow::rule($this->tenant, 'booking.enabled');
    }

    public function definitions(): array
    {
        $tools = [
            ['name' => 'ver_servicios', 'description' => 'Lista los servicios que se pueden reservar, con su precio, duración e identificador.', 'schema' => ['type' => 'object', 'properties' => (object) [], 'required' => []]],
            ['name' => 'ver_sedes', 'description' => 'Lista las sedes con su dirección y su horario de atención por día.', 'schema' => ['type' => 'object', 'properties' => (object) [], 'required' => []]],
            ['name' => 'buscar_horarios', 'description' => 'Devuelve las horas libres reales de un servicio en una sede para una fecha. Úsala siempre antes de ofrecer una hora: nunca inventes horarios.', 'schema' => [
                'type' => 'object',
                'properties' => [
                    'service_id'      => ['type' => 'string', 'description' => 'Identificador del servicio (de ver_servicios).'],
                    'branch_id'       => ['type' => 'string', 'description' => 'Identificador de la sede (de ver_sedes).'],
                    'date'            => ['type' => 'string', 'description' => 'Fecha en formato AAAA-MM-DD.'],
                    'professional_id' => ['type' => 'string', 'description' => 'Opcional: identificador del profesional.'],
                ],
                'required' => ['service_id', 'branch_id', 'date'],
            ]],
            ['name' => 'pasar_a_una_persona', 'description' => 'Pasa la conversación a una persona del equipo. Úsala si el cliente lo pide, si no entiendes lo que quiere, si se queja o si la pregunta no la puedes responder con las herramientas.', 'schema' => [
                'type' => 'object',
                'properties' => ['reason' => ['type' => 'string', 'description' => 'Motivo breve para el equipo.']],
                'required' => ['reason'],
            ]],
        ];

        if ($this->customer) {
            $tools[] = ['name' => 'ver_mis_citas', 'description' => 'Lista las próximas citas del cliente con su identificador y código.', 'schema' => ['type' => 'object', 'properties' => (object) [], 'required' => []]];
        }

        if ($this->customer && $this->canBook()) {
            $tools[] = ['name' => 'reservar_cita', 'description' => 'Reserva una cita para el cliente. Solo después de que el cliente confirme servicio, sede, día y hora de una lista de buscar_horarios.', 'schema' => [
                'type' => 'object',
                'properties' => [
                    'service_id'      => ['type' => 'string'],
                    'branch_id'       => ['type' => 'string'],
                    'starts_at'       => ['type' => 'string', 'description' => 'Fecha y hora local en formato AAAA-MM-DDTHH:MM.'],
                    'professional_id' => ['type' => 'string', 'description' => 'Opcional.'],
                ],
                'required' => ['service_id', 'branch_id', 'starts_at'],
            ]];
            $tools[] = ['name' => 'reprogramar_cita', 'description' => 'Cambia la fecha y hora de una cita del cliente a una hora libre que el cliente eligió.', 'schema' => [
                'type' => 'object',
                'properties' => ['appointment_id' => ['type' => 'string'], 'starts_at' => ['type' => 'string', 'description' => 'AAAA-MM-DDTHH:MM'], 'professional_id' => ['type' => 'string']],
                'required' => ['appointment_id', 'starts_at'],
            ]];
            $tools[] = ['name' => 'cancelar_cita', 'description' => 'Cancela una cita del cliente cuando él lo pide claramente.', 'schema' => [
                'type' => 'object',
                'properties' => ['appointment_id' => ['type' => 'string'], 'reason' => ['type' => 'string']],
                'required' => ['appointment_id'],
            ]];
            $tools[] = ['name' => 'confirmar_cita', 'description' => 'Confirma la asistencia del cliente a una cita pendiente.', 'schema' => [
                'type' => 'object',
                'properties' => ['appointment_id' => ['type' => 'string']],
                'required' => ['appointment_id'],
            ]];
        }

        return $tools;
    }

    public function names(): array
    {
        return array_column($this->definitions(), 'name');
    }

    public function run(string $name, array $input): array
    {
        if (!in_array($name, $this->names(), true)) {
            return ['ok' => false, 'error' => 'Esa acción no está permitida.'];
        }

        try {
            return match ($name) {
                'ver_servicios'       => $this->services(),
                'ver_sedes'           => $this->branches(),
                'buscar_horarios'     => $this->slots($input),
                'ver_mis_citas'       => $this->appointments(),
                'reservar_cita'       => $this->book($input),
                'reprogramar_cita'    => $this->move($input),
                'cancelar_cita'       => $this->cancel($input),
                'confirmar_cita'      => $this->confirm($input),
                'pasar_a_una_persona' => $this->toHuman($input),
            };
        } catch (ValidationException $e) {
            return ['ok' => false, 'error' => collect($e->errors())->flatten()->first()];
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return ['ok' => false, 'error' => $e->getMessage() ?: 'No se pudo hacer.'];
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return ['ok' => false, 'error' => 'No se encontró lo que buscas. Revisa el identificador.'];
        }
    }

    private function serviceQuery()
    {
        return Service::where('tenant_id', $this->tenant->id)->where('kind', Service::KIND_INDIVIDUAL)->where('online_booking', true)->with('status');
    }

    private function services(): array
    {
        return ['ok' => true, 'services' => $this->serviceQuery()->orderBy('name')->get()
            ->reject(fn (Service $s) => $s->status?->slug === 'inactive')
            ->map(fn (Service $s) => [
                'id'       => $s->id,
                'nombre'   => $s->name,
                'precio'   => Currency::format((float) $s->price, Currency::of($this->tenant)),
                'minutos'  => (int) $s->duration_minutes,
                'sedes'    => $s->branch_ids ?: 'todas',
            ])->values()->all()];
    }

    private function branches(): array
    {
        return ['ok' => true, 'sedes' => Branch::where('tenant_id', $this->tenant->id)->where('status', 'active')->orderBy('name')->get()
            ->map(function (Branch $b) {
                $hours = BranchHours::of($b->id);

                return [
                    'id'        => $b->id,
                    'nombre'    => $b->name,
                    'direccion' => $b->address ?? null,
                    'horario'   => $hours ? collect(self::WEEKDAYS)->map(fn ($day, $n) => "{$day}: " . BranchHours::describe($hours, $n))->values()->all() : 'según la agenda de cada profesional',
                ];
            })->values()->all()];
    }

    private function slots(array $input): array
    {
        if (!AppointmentFlow::rule($this->tenant, 'booking.enabled')) {
            return ['ok' => false, 'error' => 'La reserva en línea está apagada: ofrece pasar con una persona.'];
        }

        $service = $this->serviceQuery()->findOrFail((string) ($input['service_id'] ?? ''));
        $branch = Branch::where('tenant_id', $this->tenant->id)->where('status', 'active')->findOrFail((string) ($input['branch_id'] ?? ''));
        $timezone = AppointmentScheduler::timezone($branch, $this->tenant);
        $date = Carbon::createFromFormat('Y-m-d', (string) ($input['date'] ?? ''), $timezone);

        if (!$date || $date->copy()->endOfDay()->isPast()) {
            return ['ok' => false, 'error' => 'Esa fecha ya pasó o no es válida.'];
        }

        $professional = !empty($input['professional_id']) && AppointmentFlow::rule($this->tenant, 'booking.choose_professional')
            ? Professional::where('tenant_id', $this->tenant->id)->with('status')->find((string) $input['professional_id'])
            : null;

        $groups = (new AppointmentScheduler($this->tenant, true))->slots($service, $branch, $date, $professional, null);
        $times = collect($groups)->flatMap(fn (array $g) => $g['slots'])->unique()->sort()->values();

        return [
            'ok'      => true,
            'fecha'   => $date->format('Y-m-d') . ' (' . self::WEEKDAYS[(int) $date->dayOfWeek] . ')',
            'horas'   => $times->take(16)->all(),
            'mas'     => max(0, $times->count() - 16),
            'aviso'   => $times->isEmpty() ? 'No hay horas libres ese día.' : null,
        ];
    }

    private function appointments(): array
    {
        return ['ok' => true, 'citas' => Appointment::where('tenant_id', $this->tenant->id)->where('customer_id', $this->customer->id)
            ->whereIn('status', [AppointmentFlow::PENDING, AppointmentFlow::CONFIRMED])
            ->where('starts_at', '>', now())->orderBy('starts_at')->limit(10)
            ->with(['service:id,name', 'branch', 'professional:id,display_name'])->get()
            ->map(fn (Appointment $a) => [
                'id'          => $a->id,
                'codigo'      => $a->code,
                'servicio'    => $a->service?->name,
                'cuando'      => $a->starts_at->copy()->setTimezone(AppointmentScheduler::timezone($a->branch ?? new Branch(), $this->tenant))->format('Y-m-d H:i'),
                'sede'        => $a->branch?->name,
                'profesional' => $a->professional?->display_name,
                'estado'      => $a->status === AppointmentFlow::PENDING ? 'por confirmar' : 'confirmada',
            ])->all()];
    }

    private function controller(): ChatBookingController
    {
        return app(ChatBookingController::class);
    }

    private function answer($response, string $action): array
    {
        $data = $response->getData(true);

        if ($response->getStatusCode() >= 400) {
            return ['ok' => false, 'error' => $data['message'] ?? 'No se pudo hacer.'];
        }

        $code = (string) ($data['code'] ?? '');
        $this->done[] = ['action' => $action, 'code' => $code, 'id' => $data['id'] ?? null];

        Audit::log(request(), 'assistant.acted', [
            'tenant'       => $this->tenant->id,
            'actor'        => null,
            'actor_name'   => 'Asistente de WhatsApp',
            'entity_type'  => 'appointment',
            'entity_id'    => $data['id'] ?? null,
            'entity_label' => $code,
            'after'        => ['assistant_action' => ['booking' => 'Reservó', 'move' => 'Reprogramó', 'cancel' => 'Canceló', 'confirm' => 'Confirmó'][$action] ?? $action, 'conversation' => $this->conversation->id],
        ]);

        return ['ok' => true, 'codigo' => $code, 'cuando' => $data['starts_at'] ?? null, 'estado' => $data['status_name'] ?? null];
    }

    private function book(array $input): array
    {
        if ($this->simulate) {
            return $this->pretend('booking', $input);
        }

        return $this->answer($this->controller()->bookFromChat($this->tenant, $this->customer, [
            'service_id'      => $input['service_id'] ?? null,
            'branch_id'       => $input['branch_id'] ?? null,
            'professional_id' => $input['professional_id'] ?? null,
            'starts_at'       => $input['starts_at'] ?? null,
        ]), 'booking');
    }

    private function ownId(array $input): string
    {
        $id = (string) ($input['appointment_id'] ?? '');
        abort_unless(Str::isUuid($id), 404, 'No se encontró esa cita.');

        return $id;
    }

    private function move(array $input): array
    {
        if ($this->simulate) {
            return $this->pretend('move', $input);
        }

        return $this->answer($this->controller()->moveFromChat($this->customer, $this->ownId($input), array_filter(['starts_at' => $input['starts_at'] ?? null, 'professional_id' => $input['professional_id'] ?? null])), 'move');
    }

    private function cancel(array $input): array
    {
        if ($this->simulate) {
            return $this->pretend('cancel', $input);
        }

        return $this->answer($this->controller()->cancelFromChat($this->customer, $this->ownId($input), mb_substr(trim((string) ($input['reason'] ?? '')) ?: 'Cancelada por WhatsApp', 0, 255)), 'cancel');
    }

    private function confirm(array $input): array
    {
        if ($this->simulate) {
            return $this->pretend('confirm', $input);
        }

        return $this->answer($this->controller()->confirmFromChat($this->customer, $this->ownId($input)), 'confirm');
    }

    private function pretend(string $action, array $input): array
    {
        $note = 'Esto es el simulador: no se guardó nada. Responde al cliente como si se hubiera hecho.';

        if ($action === 'booking') {
            $starts = (string) ($input['starts_at'] ?? '');
            $date = substr($starts, 0, 10);
            $time = substr($starts, 11, 5);
            $free = $this->slots(['service_id' => $input['service_id'] ?? null, 'branch_id' => $input['branch_id'] ?? null, 'date' => $date, 'professional_id' => $input['professional_id'] ?? null]);

            if (!$free['ok']) {
                return $free;
            }

            if (!in_array($time, $free['horas'], true)) {
                return ['ok' => false, 'error' => 'Esa hora no está libre. Ofrece otra de buscar_horarios.'];
            }
        } else {
            $id = $this->ownId($input);
            $exists = Appointment::where('tenant_id', $this->tenant->id)->where('customer_id', $this->customer->id)->where('id', $id)->exists();

            if (!$exists) {
                return ['ok' => false, 'error' => 'No se encontró esa cita entre las del cliente.'];
            }
        }

        $this->done[] = ['action' => $action, 'code' => 'PRUEBA', 'id' => null, 'simulated' => true];

        return ['ok' => true, 'codigo' => 'PRUEBA', 'cuando' => $input['starts_at'] ?? null, 'simulado' => true, 'aviso' => $note];
    }

    private function toHuman(array $input): array
    {
        $this->handoff = mb_substr(trim((string) ($input['reason'] ?? '')) ?: 'El asistente pidió ayuda de una persona.', 0, 255);

        return ['ok' => true, 'mensaje' => 'Listo: una persona del equipo seguirá la conversación. Despídete brevemente.'];
    }
}
