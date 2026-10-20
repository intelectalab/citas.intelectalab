<?php

namespace App\Support;

use App\Models\Appointment;
use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Messaging
{
    public const NOT_READY = 'Falta actualizar la base de datos para enviar mensajes: carga init.sql, seed.sql y seed_demo.sql.';

    public const NOT_CONFIGURED = 'No enviado: tu empresa aún no configura su correo (Configuración → Correo).';

    public const MAX_ATTEMPTS = 3;

    public const STATUS_LABELS = [
        'scheduled' => 'Programado',
        'queued'    => 'Por enviar',
        'sent'      => 'Enviado',
        'simulated' => 'Simulado',
        'failed'    => 'Falló',
        'not_sent'  => 'No enviado',
        'cancelled' => 'Cancelado',
    ];

    public const VARIABLES = [
        'Nombre'      => 'Nombre del cliente',
        'Servicio'    => 'Servicio',
        'Fecha'       => 'Fecha',
        'Hora'        => 'Hora',
        'Profesional' => 'Profesional',
        'Sede'        => 'Sede',
        'Código'      => 'Código de la cita',
        'Empresa'     => 'Tu empresa',
        'Paquete'     => 'Paquete',
        'Sesiones'    => 'Sesiones que le quedan',
        'Vence'       => 'Fecha de vencimiento',
        'Monto'       => 'Monto',
        'Detalle'     => 'Detalle de la compra',
        'Comprobante' => 'Número de comprobante',
        'Link'        => 'Link de pago',
        'Portal'      => 'Dirección del portal',
        'Correo'      => 'Correo del cliente',
        'Control'     => 'Nombre del control',
    ];

    public const KINDS = [
        'booking_confirmation' => [
            'label' => 'Confirmación de reserva',
            'when'  => 'Al agendar una cita, también desde el portal. No se envía si la cita empieza dentro de la próxima hora (el cliente ya está ahí).',
            'vars'  => ['Nombre', 'Servicio', 'Fecha', 'Hora', 'Profesional', 'Sede', 'Código', 'Empresa'],
            'subject' => 'Tu cita del [Fecha] está agendada',
            'body'  => "Hola [Nombre]:\n\nTu cita de [Servicio] quedó agendada para el [Fecha] a las [Hora] con [Profesional], en [Sede].\n\nCódigo de tu cita: [Código].\n\nSi no puedes asistir, avísanos con anticipación.\n\n[Empresa]",
        ],
        'reminder' => [
            'label' => 'Recordatorio de cita',
            'when'  => 'Antes de cada cita pendiente o confirmada.',
            'vars'  => ['Nombre', 'Servicio', 'Fecha', 'Hora', 'Profesional', 'Sede', 'Código', 'Empresa'],
            'subject' => 'Recordatorio: tu cita es el [Fecha] a las [Hora]',
            'body'  => "Hola [Nombre]:\n\nTe recordamos tu cita de [Servicio] el [Fecha] a las [Hora] con [Profesional], en [Sede].\n\nSi no puedes asistir, avísanos lo antes posible para darle el horario a otra persona.\n\n[Empresa]",
            'options' => ['hours' => 24, 'second' => false],
        ],
        'appointment_cancelled' => [
            'label' => 'Cita cancelada',
            'when'  => 'Al cancelar una cita, la cancele la sede o el cliente.',
            'vars'  => ['Nombre', 'Servicio', 'Fecha', 'Hora', 'Código', 'Empresa'],
            'subject' => 'Tu cita del [Fecha] se canceló',
            'body'  => "Hola [Nombre]:\n\nTu cita de [Servicio] del [Fecha] a las [Hora] quedó cancelada.\n\nSi quieres una nueva cita, escríbenos o resérvala en nuestro portal.\n\n[Empresa]",
        ],
        'appointment_rescheduled' => [
            'label' => 'Cita reprogramada',
            'when'  => 'Al cambiar la fecha o la hora de una cita.',
            'vars'  => ['Nombre', 'Servicio', 'Fecha', 'Hora', 'Profesional', 'Sede', 'Código', 'Empresa'],
            'subject' => 'Tu cita cambió al [Fecha] a las [Hora]',
            'body'  => "Hola [Nombre]:\n\nTu cita de [Servicio] ahora es el [Fecha] a las [Hora] con [Profesional], en [Sede].\n\nCódigo de tu cita: [Código].\n\n[Empresa]",
        ],
        'receipt' => [
            'label' => 'Comprobante de pago',
            'when'  => 'Al pagarse una venta, con su boleta o factura.',
            'vars'  => ['Nombre', 'Detalle', 'Monto', 'Comprobante', 'Empresa'],
            'subject' => 'Tu comprobante [Comprobante]',
            'body'  => "Hola [Nombre]:\n\nGracias por tu pago. Este es el detalle:\n\n[Detalle]\n\nTotal pagado: [Monto]\nComprobante: [Comprobante]\n\n[Empresa]",
        ],
        'payment_link' => [
            'label' => 'Link de pago',
            'when'  => 'Cuando alguien del personal pulsa "Enviar por correo" en el panel de cobro.',
            'vars'  => ['Nombre', 'Detalle', 'Monto', 'Link', 'Vence', 'Empresa'],
            'subject' => 'Tu link de pago de [Empresa]',
            'body'  => "Hola [Nombre]:\n\nPuedes pagar [Monto] por [Detalle] desde este link:\n[Link]\n\nEl link vence el [Vence].\n\n[Empresa]",
            'manual' => true,
        ],
        'package_low' => [
            'label' => 'Te queda 1 sesión',
            'when'  => 'Al descontarse la penúltima sesión de un paquete.',
            'vars'  => ['Nombre', 'Paquete', 'Vence', 'Empresa'],
            'subject' => 'Te queda 1 sesión de tu paquete',
            'body'  => "Hola [Nombre]:\n\nUsaste una sesión de tu paquete [Paquete]. Te queda 1 sesión, válida hasta el [Vence].\n\nSi quieres renovarlo, pregúntanos en tu próxima visita.\n\n[Empresa]",
        ],
        'package_expiring' => [
            'label' => 'Tu paquete vence pronto',
            'when'  => 'Unos días antes de que venza un paquete con sesiones sin usar.',
            'vars'  => ['Nombre', 'Paquete', 'Sesiones', 'Vence', 'Empresa'],
            'subject' => 'Tu paquete vence el [Vence]',
            'body'  => "Hola [Nombre]:\n\nTu paquete [Paquete] vence el [Vence] y aún te quedan [Sesiones]. Reserva tus citas antes de esa fecha para no perderlas.\n\n[Empresa]",
            'options' => ['days' => 7],
        ],
        'waitlist_slot' => [
            'label' => 'Se liberó un horario',
            'when'  => 'Cuando se libera un horario que le sirve a alguien de la lista de espera.',
            'vars'  => ['Nombre', 'Servicio', 'Fecha', 'Hora', 'Sede', 'Empresa'],
            'subject' => 'Se liberó un horario el [Fecha]',
            'body'  => "Hola [Nombre]:\n\nEstás en nuestra lista de espera para [Servicio]. Se liberó un horario el [Fecha] a las [Hora] en [Sede]. Si te interesa, resérvalo en nuestro portal o escríbenos: el primero que lo toma se lo queda.\n\n[Empresa]",
        ],
        'recall_due' => [
            'label' => 'Control por vencer',
            'when'  => 'Unos días antes de la fecha de un control pendiente.',
            'vars'  => ['Nombre', 'Control', 'Fecha', 'Empresa'],
            'subject' => 'Se acerca tu [Control]',
            'body'  => "Hola [Nombre]:\n\nSe acerca la fecha de tu [Control] ([Fecha]). Reserva tu cita en nuestro portal o escríbenos.\n\n[Empresa]",
            'options' => ['days' => 3],
        ],
        'portal_access' => [
            'label' => 'Acceso al portal',
            'when'  => 'Al darle acceso al portal a un cliente o al restablecer su contraseña.',
            'vars'  => ['Nombre', 'Portal', 'Correo', 'Empresa'],
            'subject' => 'Tu acceso al portal de [Empresa]',
            'body'  => "Hola [Nombre]:\n\nYa puedes ver tus citas y reservar en línea en:\n[Portal]\n\nEntra con tu correo ([Correo]) y, la primera vez, con tu número de documento como contraseña. Te pediremos cambiarla.\n\n[Empresa]",
        ],
    ];

    public const MANUAL_LABEL = 'Mensaje del personal';

    public const PRESETS = [
        'gmail'   => ['label' => 'Gmail', 'host' => 'smtp.gmail.com', 'port' => 587, 'security' => 'tls', 'hint' => 'Usa una "contraseña de aplicación" de tu cuenta de Google (Cuenta de Google → Seguridad → Contraseñas de aplicaciones), no tu contraseña normal.'],
        'outlook' => ['label' => 'Outlook / Microsoft 365', 'host' => 'smtp.office365.com', 'port' => 587, 'security' => 'tls', 'hint' => 'Tu cuenta debe tener activado el envío por SMTP. Si tiene verificación en dos pasos, usa una contraseña de aplicación.'],
        'brevo'   => ['label' => 'Brevo', 'host' => 'smtp-relay.brevo.com', 'port' => 587, 'security' => 'tls', 'hint' => 'En Brevo: SMTP y API → SMTP. El usuario es el que muestra ahí y la contraseña es la "clave SMTP".'],
    ];

    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('messages') && Schema::hasTable('message_settings');
    }

    public static function kindLabel(string $kind): string
    {
        return $kind === 'manual' ? self::MANUAL_LABEL : (self::KINDS[$kind]['label'] ?? $kind);
    }

    public static function integration(string $tenantId): ?object
    {
        return DB::table('tenant_integrations')->where('tenant_id', $tenantId)->where('kind', 'email')->first();
    }

    public static function emailConfig(string $tenantId): ?array
    {
        $row = self::integration($tenantId);

        if (!$row) {
            return null;
        }

        $settings = is_string($row->settings) ? json_decode($row->settings, true) : (array) $row->settings;
        $secret = $row->credentials ? json_decode(Crypt::decryptString($row->credentials), true) : [];

        return $settings + ['provider' => $row->provider, 'password' => $secret['password'] ?? null, 'updated_at' => $row->updated_at];
    }

    public static function emailReady(string $tenantId): bool
    {
        $config = self::emailConfig($tenantId);

        return $config && ($config['provider'] === 'simulated' || (!empty($config['host']) && !empty($config['from_address'])));
    }

    public static function settings(string $tenantId): array
    {
        $saved = self::ready() ? DB::table('message_settings')->where('tenant_id', $tenantId)->get()->keyBy('kind') : collect();
        $result = [];

        foreach (self::KINDS as $kind => $def) {
            $row = $saved[$kind] ?? null;
            $options = $row ? (is_string($row->options) ? json_decode($row->options, true) : (array) $row->options) : [];

            $result[$kind] = [
                'kind'     => $kind,
                'label'    => $def['label'],
                'when'     => $def['when'],
                'manual'   => (bool) ($def['manual'] ?? false),
                'enabled'  => $row ? (bool) $row->enabled : true,
                'subject'  => $row?->subject ?: $def['subject'],
                'body'     => $row?->body ?: $def['body'],
                'custom'   => (bool) ($row?->subject || $row?->body),
                'options'  => ($options ?: []) + ($def['options'] ?? []),
                'variables' => collect($def['vars'])->map(fn ($v) => ['token' => "[{$v}]", 'label' => self::VARIABLES[$v]])->values()->all(),
            ];
        }

        return $result;
    }

    public static function render(string $text, array $vars): string
    {
        return strtr($text, collect($vars)->mapWithKeys(fn ($value, $key) => ["[{$key}]" => (string) $value])->all());
    }

    public static function companyName(Tenant $tenant): string
    {
        $company = DB::table('companies')->where('tenant_id', $tenant->id)->orderBy('created_at')->first();

        return $company->trade_name ?? $company->legal_name ?? $tenant->name;
    }

    public static function longDate(Carbon $date): string
    {
        return $date->copy()->locale('es')->isoFormat('dddd D [de] MMMM');
    }

    public static function appointmentVars(Tenant $tenant, Appointment $appointment): array
    {
        $appointment->loadMissing(['customer', 'service', 'professional', 'branch']);
        $local = $appointment->starts_at->copy()->setTimezone(AppointmentScheduler::timezone($appointment->branch ?? new \App\Models\Branch(), $tenant));

        return [
            'Nombre'      => $appointment->customer?->first_name ?? '',
            'Servicio'    => $appointment->service?->name ?? '',
            'Fecha'       => self::longDate($local),
            'Hora'        => Packages::hour($local->format('H:i')),
            'Profesional' => $appointment->professional?->display_name ?? '',
            'Sede'        => $appointment->branch?->name ?? '',
            'Código'      => $appointment->code,
            'Empresa'     => self::companyName($tenant),
        ];
    }

    public static function queue(Tenant $tenant, string $kind, ?Customer $customer, array $vars, array $extra = []): ?object
    {
        try {
            if (!self::ready()) {
                return null;
            }

            $settings = self::settings($tenant->id)[$kind] ?? null;

            if (!$settings || (!$settings['enabled'] && empty($extra['force']))) {
                return null;
            }

            $vars += ['Empresa' => self::companyName($tenant), 'Nombre' => $customer?->first_name ?? '', 'Correo' => $customer?->email ?? ''];

            return self::store($tenant, [
                'kind'        => $kind,
                'customer'    => $customer,
                'subject'     => self::render($settings['subject'], $vars),
                'body'        => self::render($settings['body'], $vars),
                'scheduled_at' => $extra['scheduled_at'] ?? null,
                'branch_id'   => $extra['branch_id'] ?? null,
                'entity_type' => $extra['entity_type'] ?? null,
                'entity_id'   => $extra['entity_id'] ?? null,
                'dedupe_key'  => $extra['dedupe_key'] ?? null,
                'user'        => $extra['user'] ?? null,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public static function store(Tenant $tenant, array $data): ?object
    {
        $customer = $data['customer'];
        $email = trim((string) ($customer?->email ?? $data['to'] ?? ''));
        $scheduled = $data['scheduled_at'] ?? null;
        [$status, $error] = match (true) {
            $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) => ['not_sent', 'No enviado: el cliente no tiene un correo registrado.'],
            !self::emailReady($tenant->id) => ['not_sent', self::NOT_CONFIGURED],
            $scheduled && Carbon::parse($scheduled)->isFuture() => ['scheduled', null],
            default => ['queued', null],
        };

        if ($data['dedupe_key'] && DB::table('messages')->where('tenant_id', $tenant->id)->where('dedupe_key', $data['dedupe_key'])->exists()) {
            return null;
        }

        $id = (string) Str::uuid();

        DB::table('messages')->insertOrIgnore([
            'id'           => $id,
            'tenant_id'    => $tenant->id,
            'customer_id'  => $customer?->id,
            'branch_id'    => $data['branch_id'] ?? null,
            'channel'      => 'email',
            'kind'         => $data['kind'],
            'status'       => $status,
            'to_address'   => $email ?: null,
            'to_name'      => $customer ? \App\Support\Person::name($customer) : null,
            'subject'      => mb_substr((string) $data['subject'], 0, 255),
            'body'         => $data['body'],
            'scheduled_at' => $scheduled ? Carbon::parse($scheduled)->utc() : null,
            'error'        => $error,
            'entity_type'  => $data['entity_type'] ?? null,
            'entity_id'    => $data['entity_id'] ?? null,
            'dedupe_key'   => $data['dedupe_key'] ?? null,
            'created_by'   => $data['user']?->id,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        if ($status === 'queued') {
            self::kick($tenant->id);
        }

        return DB::table('messages')->where('id', $id)->first();
    }

    public static function kick(string $tenantId): void
    {
        $attributes = request()->attributes;
        $pending = $attributes->get('messaging_kick', []);

        if (isset($pending[$tenantId])) {
            return;
        }

        $attributes->set('messaging_kick', $pending + [$tenantId => true]);
        app()->terminating(fn () => self::process($tenantId));
    }

    public static function due(?string $tenantId = null)
    {
        return DB::table('messages')
            ->when($tenantId, fn ($q, $id) => $q->where('tenant_id', $id))
            ->where(fn ($q) => $q->where('status', 'queued')->where(fn ($q) => $q->whereNull('scheduled_at')->orWhere('scheduled_at', '<=', now()->toIso8601String()))
                ->orWhere(fn ($q) => $q->where('status', 'scheduled')->where('scheduled_at', '<=', now()->toIso8601String())));
    }

    public static function process(?string $tenantId = null, int $limit = 40): array
    {
        $done = ['sent' => 0, 'failed' => 0, 'not_sent' => 0];

        if (!self::ready()) {
            return $done;
        }

        foreach (self::due($tenantId)->orderBy('created_at')->limit($limit)->get() as $message) {
            $claimed = DB::table('messages')->where('id', $message->id)->where('status', $message->status)->where('attempts', $message->attempts)
                ->update(['attempts' => $message->attempts + 1, 'updated_at' => now()]);

            if (!$claimed) {
                continue;
            }

            $result = self::deliver($message);
            $done[$result]++;
        }

        return $done;
    }

    private static function deliver(object $message): string
    {
        $config = self::emailConfig($message->tenant_id);

        if (!$config || !self::emailReady($message->tenant_id)) {
            DB::table('messages')->where('id', $message->id)->update(['status' => 'not_sent', 'error' => self::NOT_CONFIGURED, 'updated_at' => now()]);

            return 'not_sent';
        }

        if ($config['provider'] === 'simulated') {
            DB::table('messages')->where('id', $message->id)->update(['status' => 'simulated', 'provider' => 'simulated', 'sent_at' => now(), 'error' => null, 'updated_at' => now()]);

            return 'sent';
        }

        try {
            self::send($config, $message->to_address, $message->to_name, (string) $message->subject, $message->body, $message->tenant_id);
            DB::table('messages')->where('id', $message->id)->update(['status' => 'sent', 'provider' => 'smtp', 'sent_at' => now(), 'error' => null, 'updated_at' => now()]);

            return 'sent';
        } catch (\Throwable $e) {
            $attempts = (int) $message->attempts + 1;
            $final = $attempts >= self::MAX_ATTEMPTS;

            DB::table('messages')->where('id', $message->id)->update([
                'status'       => $final ? 'failed' : 'queued',
                'scheduled_at' => $final ? $message->scheduled_at : now()->addMinutes(5 * $attempts),
                'error'        => mb_substr(self::explain($e), 0, 500),
                'updated_at'   => now(),
            ]);

            return 'failed';
        }
    }

    public static function explain(\Throwable $e): string
    {
        $raw = $e->getMessage();

        return match (true) {
            str_contains($raw, '535') || stripos($raw, 'authentication') !== false || stripos($raw, 'Username and Password not accepted') !== false
                => 'El servidor de correo no aceptó el usuario o la contraseña. Revisa los datos en Configuración → Correo (en Gmail se usa una contraseña de aplicación).',
            stripos($raw, 'Connection could not be established') !== false || stripos($raw, 'getaddrinfo') !== false || stripos($raw, 'timed out') !== false
                => 'No se pudo conectar con el servidor de correo. Revisa el servidor y el puerto, y que haya internet.',
            stripos($raw, 'certificate') !== false || stripos($raw, 'SSL') !== false || stripos($raw, 'TLS') !== false
                => 'Falló la conexión segura con el servidor de correo. Prueba con la otra opción de seguridad (TLS con puerto 587 o SSL con puerto 465).',
            stripos($raw, 'sender') !== false || stripos($raw, 'from') !== false
                => 'El servidor de correo no acepta ese correo remitente. Usa el mismo correo de la cuenta.',
            default => 'El servidor de correo respondió con un error: ' . mb_substr($raw, 0, 300),
        };
    }

    public static function send(array $config, string $to, ?string $toName, string $subject, string $body, string $tenantId): void
    {
        $mailer = Mail::build([
            'transport' => 'smtp',
            'scheme'    => ($config['security'] ?? 'tls') === 'ssl' ? 'smtps' : 'smtp',
            'host'      => $config['host'],
            'port'      => (int) ($config['port'] ?? 587),
            'username'  => $config['username'] ?? null,
            'password'  => $config['password'] ?? null,
            'timeout'   => 20,
        ]);

        $html = self::html($body, $config['from_name'] ?? '');

        $mailer->html($html, function ($mail) use ($config, $to, $toName, $subject) {
            $mail->to($to, $toName)->from($config['from_address'], $config['from_name'] ?? null)->subject($subject);

            if (!empty($config['reply_to'])) {
                $mail->replyTo($config['reply_to'], $config['from_name'] ?? null);
            }
        });
    }

    public static function html(string $body, string $company): string
    {
        $escaped = nl2br(e($body));
        $linked = preg_replace('#(https?://[^\s<]+)#', '<a href="$1" style="color:#3F768C">$1</a>', $escaped);

        return '<!doctype html><html><body style="margin:0;padding:24px;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#1f2937">'
            . '<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:16px;padding:28px;border:1px solid #e2e8f0">'
            . ($company !== '' ? '<p style="margin:0 0 16px;font-weight:bold;color:#022340;font-size:16px">' . e($company) . '</p>' : '')
            . '<div style="font-size:14px;line-height:1.6">' . $linked . '</div>'
            . '</div></body></html>';
    }

    public static function present(object $message, ?object $customer = null, ?string $by = null): array
    {
        $iso = fn ($value) => $value ? Carbon::parse($value)->toIso8601String() : null;

        return [
            'id'           => $message->id,
            'kind'         => $message->kind,
            'kind_label'   => self::kindLabel($message->kind),
            'channel'      => $message->channel,
            'status'       => $message->status,
            'status_label' => self::STATUS_LABELS[$message->status] ?? $message->status,
            'to'           => $message->to_address,
            'to_name'      => $message->to_name,
            'customer'     => $customer ? ['id' => $customer->id, 'name' => \App\Support\Person::name($customer)] : null,
            'subject'      => $message->subject,
            'body'         => $message->body,
            'scheduled_at' => $iso($message->scheduled_at),
            'sent_at'      => $iso($message->sent_at),
            'created_at'   => $iso($message->created_at),
            'attempts'     => (int) $message->attempts,
            'error'        => $message->error,
            'by'           => $by,
            'editable'     => $message->status === 'scheduled',
            'retryable'    => in_array($message->status, ['failed', 'not_sent'], true),
        ];
    }

    public static function appointmentChanged(Tenant $tenant, Appointment $appointment, ?string $from, string $to): void
    {
        try {
            if (!self::ready() || !$appointment->customer_id) {
                return;
            }

            $appointment->loadMissing('customer');
            $customer = $appointment->customer;
            $base = ['branch_id' => $appointment->branch_id, 'entity_type' => 'appointment', 'entity_id' => $appointment->id];

            if ($from === null && in_array($to, [AppointmentFlow::PENDING, AppointmentFlow::CONFIRMED], true)) {
                if ($appointment->starts_at->lte(now()->addHour())) {
                    return;
                }

                self::queue($tenant, 'booking_confirmation', $customer, self::appointmentVars($tenant, $appointment), $base + ['dedupe_key' => "booking:{$appointment->id}"]);

                return;
            }

            if ($to === AppointmentFlow::CANCELLED && $from !== $to) {
                if ($appointment->starts_at->isPast()) {
                    return;
                }

                self::cancelPendingFor($tenant->id, $appointment->id);
                self::queue($tenant, 'appointment_cancelled', $customer, self::appointmentVars($tenant, $appointment), $base + ['dedupe_key' => "cancel:{$appointment->id}"]);

                return;
            }

            if ($from !== null && $from === $to && in_array($to, AppointmentFlow::RESCHEDULABLE, true)) {
                self::queue($tenant, 'appointment_rescheduled', $customer, self::appointmentVars($tenant, $appointment), $base + ['dedupe_key' => "move:{$appointment->id}:" . $appointment->starts_at->timestamp]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function cancelPendingFor(string $tenantId, string $appointmentId): void
    {
        DB::table('messages')->where('tenant_id', $tenantId)->where('entity_type', 'appointment')->where('entity_id', $appointmentId)
            ->where('kind', 'reminder')->whereIn('status', ['scheduled', 'queued'])
            ->update(['status' => 'cancelled', 'error' => 'La cita se canceló antes del envío.', 'updated_at' => now()]);
    }

    public static function receipt(Tenant $tenant, object $sale, ?User $user = null, bool $force = false): ?object
    {
        try {
            if (!self::ready() || !$sale->customer_id) {
                return null;
            }

            $customer = Customer::find($sale->customer_id);
            $invoice = Invoicing::ofSale($sale->id);
            $lines = DB::table('sale_items')->where('sale_id', $sale->id)->get(['description', 'quantity', 'total']);
            $currency = $sale->currency ?? Currency::of($tenant);

            return self::queue($tenant, 'receipt', $customer, [
                'Detalle'     => $lines->map(fn ($l) => '• ' . ((float) $l->quantity > 1 ? (int) $l->quantity . ' × ' : '') . $l->description . ': ' . Currency::format((float) $l->total, $currency))->implode("\n"),
                'Monto'       => Currency::format((float) $sale->total, $currency),
                'Comprobante' => $invoice ? sprintf('%s-%08d', $invoice->serie, $invoice->number) : $sale->code,
            ], [
                'branch_id'   => $sale->branch_id,
                'entity_type' => 'sale',
                'entity_id'   => $sale->id,
                'dedupe_key'  => $force ? null : "receipt:{$sale->id}",
                'user'        => $user,
                'force'       => $force,
            ]);
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }

    public static function packageLow(Tenant $tenant, object $package): void
    {
        $left = (int) DB::table('customer_package_items')->where('customer_package_id', $package->id)->selectRaw('sum(sessions_total - sessions_used) as left_count')->value('left_count');

        if ($left !== 1) {
            return;
        }

        $customer = Customer::find($package->customer_id);
        $expires = Carbon::parse($package->expires_at)->setTimezone($tenant->zone());

        self::queue($tenant, 'package_low', $customer, ['Paquete' => $package->name, 'Vence' => self::longDate($expires)], [
            'entity_type' => 'package', 'entity_id' => $package->id, 'dedupe_key' => "pkglow:{$package->id}",
        ]);
    }

    public static function waitlistSlot(Tenant $tenant, Appointment $appointment): void
    {
        try {
            if (!self::ready() || !$appointment->starts_at->isFuture()) {
                return;
            }

            $vars = self::appointmentVars($tenant, $appointment);

            foreach (Waitlist::matching($tenant, $appointment)->with(['customer', 'service'])->limit(20)->get() as $entry) {
                self::queue($tenant, 'waitlist_slot', $entry->customer, ['Servicio' => $entry->service?->name ?? $vars['Servicio']] + $vars, [
                    'branch_id' => $appointment->branch_id, 'entity_type' => 'waitlist', 'entity_id' => $entry->id,
                    'dedupe_key' => "waitlist:{$entry->id}:{$appointment->id}",
                ]);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    public static function portalAccess(Tenant $tenant, Customer $customer, ?User $user): ?object
    {
        return self::queue($tenant, 'portal_access', $customer, [
            'Portal' => rtrim((string) config('app.frontend_url'), '/') . '/login',
            'Correo' => $customer->email,
        ], ['entity_type' => 'customer', 'entity_id' => $customer->id, 'user' => $user]);
    }

    public static function generate(Tenant $tenant, bool $force = false): void
    {
        try {
            if (!self::ready() || !self::emailReady($tenant->id) || (!$force && !Cache::add('messaging-generate:' . $tenant->id, 1, 300))) {
                return;
            }

            $settings = self::settings($tenant->id);
            $timezone = $tenant->zone();

            if ($settings['reminder']['enabled']) {
                $windows = [['key' => 'reminder', 'hours' => max(1, (int) ($settings['reminder']['options']['hours'] ?? 24))]];

                if (!empty($settings['reminder']['options']['second'])) {
                    $windows[] = ['key' => 'reminder2', 'hours' => 2];
                }

                foreach ($windows as $window) {
                    Appointment::where('tenant_id', $tenant->id)
                        ->whereIn('status', [AppointmentFlow::PENDING, AppointmentFlow::CONFIRMED])
                        ->where('starts_at', '>', now()->toIso8601String())
                        ->where('starts_at', '<=', now()->addHours($window['hours'])->toIso8601String())
                        ->whereRaw('created_at < starts_at - make_interval(hours => ?)', [$window['hours']])
                        ->with(['customer', 'service', 'professional', 'branch'])
                        ->limit(300)
                        ->get()
                        ->each(fn (Appointment $a) => self::queue($tenant, 'reminder', $a->customer, self::appointmentVars($tenant, $a), [
                            'branch_id' => $a->branch_id, 'entity_type' => 'appointment', 'entity_id' => $a->id,
                            'dedupe_key' => "{$window['key']}:{$a->id}:" . $a->starts_at->timestamp,
                        ]));
                }
            }

            if ($settings['package_expiring']['enabled'] && Packages::ready()) {
                $days = max(1, (int) ($settings['package_expiring']['options']['days'] ?? 7));

                foreach (DB::table('customer_packages')->where('tenant_id', $tenant->id)->where('status', 'active')
                    ->where('expires_at', '>', now()->toIso8601String())->where('expires_at', '<=', now()->addDays($days)->toIso8601String())->limit(300)->get() as $package) {
                    $left = (int) DB::table('customer_package_items')->where('customer_package_id', $package->id)->selectRaw('sum(sessions_total - sessions_used) as left_count')->value('left_count');

                    if ($left <= 0) {
                        continue;
                    }

                    self::queue($tenant, 'package_expiring', Customer::find($package->customer_id), [
                        'Paquete'  => $package->name,
                        'Sesiones' => Packages::sessions($left),
                        'Vence'    => self::longDate(Carbon::parse($package->expires_at)->setTimezone($timezone)),
                    ], ['entity_type' => 'package', 'entity_id' => $package->id, 'dedupe_key' => "pkgexp:{$package->id}:" . Carbon::parse($package->expires_at)->format('Ymd')]);
                }
            }

            if ($settings['recall_due']['enabled'] && Schema::hasTable('recalls')) {
                $days = max(0, (int) ($settings['recall_due']['options']['days'] ?? 3));
                $today = now($timezone)->startOfDay();

                foreach (\App\Models\Recall::where('tenant_id', $tenant->id)->whereIn('status', \App\Models\Recall::OPEN)
                    ->whereBetween('due_date', [$today->format('Y-m-d'), $today->copy()->addDays($days)->format('Y-m-d')])
                    ->with('customer')->limit(300)->get() as $recall) {
                    self::queue($tenant, 'recall_due', $recall->customer, [
                        'Control' => $recall->label ?: 'control',
                        'Fecha'   => self::longDate(Carbon::parse($recall->due_date, $timezone)),
                    ], ['entity_type' => 'recall', 'entity_id' => $recall->id, 'dedupe_key' => "recall:{$recall->id}"]);
                }
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
