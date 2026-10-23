<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Tenant;
use App\Support\Access;
use App\Support\Currency;
use App\Support\EmailDesign;
use App\Support\Messaging;
use App\Support\PaymentLinks;
use App\Support\RichText;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class MessageController extends Controller
{
    private const LOCAL_FORMAT = 'Y-m-d\TH:i';

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 403);
        abort_unless(Messaging::ready(), 503, Messaging::NOT_READY);

        return $tenant->loadMissing('settings');
    }

    private function timezone(Tenant $tenant): string
    {
        return $tenant->zone();
    }

    private function emailStatus(Tenant $tenant): array
    {
        $config = Messaging::emailConfig($tenant->id);
        $iso = fn ($value) => $value ? Carbon::parse($value)->toIso8601String() : null;

        if (!$config) {
            return ['state' => 'none', 'label' => 'Sin configurar: no se envían correos', 'config' => null];
        }

        if ($config['provider'] === 'simulated') {
            return ['state' => 'simulated', 'label' => 'Probar sin enviar: los mensajes se arman pero no salen', 'config' => ['provider' => 'simulated', 'saved_at' => $iso($config['updated_at'])]];
        }

        $state = match (true) {
            !empty($config['test_ok']) => 'ok',
            isset($config['test_ok']) && $config['test_ok'] === false => 'error',
            default => 'untested',
        };

        return [
            'state'  => $state,
            'label'  => match ($state) {
                'ok'    => 'Funcionando: los correos salen desde ' . $config['from_address'],
                'error' => 'La última prueba falló: revisa los datos',
                default => 'Configurado, falta enviar un correo de prueba',
            },
            'config' => [
                'provider'      => 'smtp',
                'preset'        => $config['preset'] ?? null,
                'host'          => $config['host'] ?? '',
                'port'          => (int) ($config['port'] ?? 587),
                'security'      => $config['security'] ?? 'tls',
                'username'      => $config['username'] ?? '',
                'from_address'  => $config['from_address'] ?? '',
                'from_name'     => $config['from_name'] ?? '',
                'reply_to'      => $config['reply_to'] ?? '',
                'password_saved' => !empty($config['password']) ? str_repeat('•', 8) . mb_substr((string) $config['password'], -2) : null,
                'tested_at'     => $iso($config['tested_at'] ?? null),
                'test_message'  => $config['test_message'] ?? null,
                'saved_at'      => $iso($config['updated_at']),
            ],
        ];
    }

    private function customers(array $ids): array
    {
        return DB::table('customers')->whereIn('id', array_filter(array_unique($ids)))->get(['id', 'first_name', 'last_name'])->keyBy('id')->all();
    }

    private function one(string $id): array
    {
        $message = DB::table('messages as m')->leftJoin('users as u', 'u.id', '=', 'm.created_by')->where('m.id', $id)->first(['m.*', 'u.first_name as by_first', 'u.last_name as by_last']);
        $customer = $message->customer_id ? DB::table('customers')->where('id', $message->customer_id)->first(['id', 'first_name', 'last_name']) : null;

        return Messaging::present($message, $customer, trim("{$message->by_first} {$message->by_last}") ?: null);
    }

    public function index(Request $request)
    {
        $tenant = $this->tenant($request);
        $user = $request->user();
        $customerId = $request->validate(['customer_id' => 'nullable|uuid'])['customer_id'] ?? null;

        Messaging::generate($tenant);

        $rows = DB::table('messages as m')
            ->leftJoin('users as u', 'u.id', '=', 'm.created_by')
            ->where('m.tenant_id', $tenant->id)
            ->when($customerId, fn ($q, $id) => $q->where('m.customer_id', $id))
            ->when(Access::branchLimit($user), fn ($q, $id) => $q->where(fn ($q) => $q->whereNull('m.branch_id')->orWhere('m.branch_id', $id)))
            ->orderByDesc('m.created_at')
            ->limit(500)
            ->get(['m.*', 'u.first_name as by_first', 'u.last_name as by_last']);

        $customers = $this->customers($rows->pluck('customer_id')->all());

        return response()->json([
            'messages' => $rows->map(fn ($m) => Messaging::present($m, $customers[$m->customer_id] ?? null, trim("{$m->by_first} {$m->by_last}") ?: null))->values(),
            'email'    => $this->emailStatus($tenant),
            'kinds'    => collect(Messaging::KINDS)->map(fn ($k, $key) => ['value' => $key, 'label' => $k['label']])->values()->push(['value' => 'manual', 'label' => Messaging::MANUAL_LABEL])->push(['value' => 'campaign', 'label' => Messaging::kindLabel('campaign')]),
            'statuses' => collect(Messaging::STATUS_LABELS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
            'due'      => Messaging::due($tenant->id)->count(),
            'can'      => [
                'create'   => $user->allows('messages.create'),
                'edit'     => $user->allows('messages.edit'),
                'settings' => $user->allows('settings.edit'),
            ],
        ]);
    }

    public function process(Request $request)
    {
        $tenant = $this->tenant($request);
        Messaging::generate($tenant, true);
        \App\Support\Campaigns::dispatchDue($tenant->id);
        $done = Messaging::process($tenant->id);

        return response()->json($done + ['due' => Messaging::due($tenant->id)->count()]);
    }

    private function input(Request $request, Tenant $tenant, bool $withCustomer): array
    {
        $validated = $request->validate(array_filter([
            'customer_id'  => $withCustomer ? 'required|uuid' : null,
            'subject'      => 'required|string|max:200',
            'body'         => 'required|string|max:20000',
            'scheduled_at' => 'nullable|date_format:' . self::LOCAL_FORMAT,
        ]), [
            'customer_id.required' => 'Elige al cliente.',
            'subject.required'     => 'Escribe el asunto.',
            'body.required'        => 'Escribe el mensaje.',
        ]);

        $at = null;

        if (!empty($validated['scheduled_at'])) {
            $at = Carbon::createFromFormat(self::LOCAL_FORMAT, $validated['scheduled_at'], $this->timezone($tenant));

            if ($at->lt(now()->addMinute())) {
                throw ValidationException::withMessages(['scheduled_at' => 'Esa hora ya pasó. Elige una hora futura o envíalo ahora.']);
            }

            if ($at->gt(now()->addYear())) {
                throw ValidationException::withMessages(['scheduled_at' => 'Se puede programar hasta con un año de anticipación.']);
            }
        }

        return $validated + ['at' => $at];
    }

    public function store(Request $request)
    {
        $tenant = $this->tenant($request);
        $data = $this->input($request, $tenant, true);
        $customer = Customer::where('tenant_id', $tenant->id)->findOrFail($data['customer_id']);

        $message = Messaging::store($tenant, [
            'kind'         => 'manual',
            'customer'     => $customer,
            'subject'      => trim($data['subject']),
            'body'         => RichText::normalize($data['body']),
            'scheduled_at' => $data['at'],
            'branch_id'    => $request->user()->branch_id,
            'entity_type'  => 'customer',
            'entity_id'    => $customer->id,
            'dedupe_key'   => null,
            'user'         => $request->user(),
        ]);

        return response()->json($this->one($message->id), 201);
    }

    private function find(Request $request, Tenant $tenant, string $id): object
    {
        $message = DB::table('messages')->where('tenant_id', $tenant->id)->where('id', $id)->first();
        abort_unless($message, 404);

        return $message;
    }

    public function update(Request $request, string $message)
    {
        $tenant = $this->tenant($request);
        $row = $this->find($request, $tenant, $message);

        if ($row->status !== 'scheduled') {
            throw ValidationException::withMessages(['message' => 'Solo se cambia un mensaje programado que aún no sale.']);
        }

        $data = $this->input($request, $tenant, false);

        if (!$data['at']) {
            throw ValidationException::withMessages(['scheduled_at' => 'Elige el día y la hora del envío.']);
        }

        DB::table('messages')->where('id', $row->id)->where('status', 'scheduled')->update([
            'subject' => trim($data['subject']), 'body' => RichText::normalize($data['body']), 'scheduled_at' => $data['at']->copy()->utc(), 'updated_at' => now(),
        ]);

        return response()->json($this->one($row->id));
    }

    public function cancel(Request $request, string $message)
    {
        $tenant = $this->tenant($request);
        $row = $this->find($request, $tenant, $message);

        if (!in_array($row->status, ['scheduled', 'queued'], true)) {
            throw ValidationException::withMessages(['message' => 'Este mensaje ya no se puede cancelar: está ' . mb_strtolower(Messaging::STATUS_LABELS[$row->status] ?? $row->status) . '.']);
        }

        $who = trim("{$request->user()->first_name} {$request->user()->last_name}");
        DB::table('messages')->where('id', $row->id)->update(['status' => 'cancelled', 'error' => "Lo canceló {$who}.", 'updated_at' => now()]);

        return response()->json($this->one($row->id));
    }

    public function retry(Request $request, string $message)
    {
        $tenant = $this->tenant($request);
        $row = $this->find($request, $tenant, $message);

        if (!in_array($row->status, ['failed', 'not_sent'], true)) {
            throw ValidationException::withMessages(['message' => 'Solo se reintenta un mensaje que falló o no se envió.']);
        }

        $customer = $row->customer_id ? Customer::find($row->customer_id) : null;
        $email = trim((string) ($customer?->email ?? $row->to_address));

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['message' => 'El cliente no tiene un correo registrado. Agrégalo en su ficha y vuelve a intentarlo.']);
        }

        if (!Messaging::emailReady($tenant->id)) {
            throw ValidationException::withMessages(['message' => 'Tu empresa aún no configura su correo: hazlo en Configuración → Correo.']);
        }

        DB::table('messages')->where('id', $row->id)->update([
            'status' => 'queued', 'to_address' => $email, 'attempts' => 0, 'error' => null, 'scheduled_at' => null, 'updated_at' => now(),
        ]);
        Messaging::process($tenant->id, 5);

        return response()->json($this->one($row->id));
    }

    public function settings(Request $request)
    {
        abort_unless($request->user()->allows('settings.view'), 403, 'Tu cuenta no tiene permiso para ver la configuración de la empresa.');
        $tenant = $this->tenant($request);

        return response()->json([
            'email'    => $this->emailStatus($tenant),
            'presets'  => collect(Messaging::PRESETS)->map(fn ($p, $key) => ['key' => $key] + $p)->values(),
            'own_email' => (bool) config('app.own_email_enabled'),
            'notices'  => array_values(Messaging::settings($tenant->id)),
            'design'   => EmailDesign::of($tenant) + ['colors' => EmailDesign::COLORS],
            'whatsapp' => ['state' => 'pending', 'label' => 'Listo para conectar cuando tu empresa elija un proveedor de WhatsApp'],
        ]);
    }

    public function saveEmail(Request $request)
    {
        abort_unless($request->user()->allows('settings.edit'), 403, 'Tu cuenta no tiene permiso para cambiar la configuración de la empresa.');
        $tenant = $this->tenant($request);

        $validated = $request->validate([
            'provider'     => ['required', Rule::in(['smtp', 'simulated'])],
            'preset'       => ['nullable', Rule::in(array_keys(Messaging::PRESETS))],
            'host'         => 'required_if:provider,smtp|nullable|string|max:190',
            'port'         => 'required_if:provider,smtp|nullable|integer|min:1|max:65535',
            'security'     => ['nullable', Rule::in(['tls', 'ssl'])],
            'username'     => 'nullable|string|max:190',
            'password'     => 'nullable|string|max:255',
            'from_address' => 'required_if:provider,smtp|nullable|email|max:190',
            'from_name'    => 'nullable|string|max:120',
            'reply_to'     => 'nullable|email|max:190',
        ], [
            'host.required_if'         => 'Escribe el servidor de correo (por ejemplo, smtp.gmail.com).',
            'port.required_if'         => 'Escribe el puerto (normalmente 587).',
            'from_address.required_if' => 'Escribe el correo desde el que se envía.',
            'from_address.email'       => 'El correo remitente no es válido.',
            'reply_to.email'           => 'El correo para respuestas no es válido.',
        ]);

        abort_if($validated['provider'] === 'smtp' && !config('app.own_email_enabled'), 422, 'Enviar desde el correo de tu empresa está en construcción. Por ahora puedes usar "Probar sin enviar".');

        $current = Messaging::integration($tenant->id);
        $old = Messaging::emailConfig($tenant->id) ?? [];

        if ($validated['provider'] === 'simulated') {
            $values = ['provider' => 'simulated', 'mode' => 'simulated', 'status' => 'active', 'settings' => json_encode(['provider' => 'simulated']), 'credentials' => null];
        } else {
            $password = trim((string) ($validated['password'] ?? '')) ?: (($old['provider'] ?? null) === 'smtp' ? ($old['password'] ?? null) : null);
            $settings = [
                'preset'       => $validated['preset'] ?? null,
                'host'         => trim($validated['host']),
                'port'         => (int) $validated['port'],
                'security'     => $validated['security'] ?? 'tls',
                'username'     => trim((string) ($validated['username'] ?? '')) ?: null,
                'from_address' => trim($validated['from_address']),
                'from_name'    => trim((string) ($validated['from_name'] ?? '')) ?: Messaging::companyName($tenant),
                'reply_to'     => trim((string) ($validated['reply_to'] ?? '')) ?: null,
            ];

            $values = [
                'provider'    => 'smtp',
                'mode'        => 'production',
                'status'      => 'active',
                'settings'    => json_encode($settings),
                'credentials' => $password ? Crypt::encryptString(json_encode(['password' => $password])) : null,
            ];
        }

        if ($current) {
            DB::table('tenant_integrations')->where('id', $current->id)->update($values + ['updated_at' => now()]);
        } else {
            DB::table('tenant_integrations')->insert($values + ['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'kind' => 'email', 'created_at' => now(), 'updated_at' => now()]);
        }

        return $this->settings($request);
    }

    public function removeEmail(Request $request)
    {
        abort_unless($request->user()->allows('settings.edit'), 403, 'Tu cuenta no tiene permiso para cambiar la configuración de la empresa.');
        $tenant = $this->tenant($request);
        DB::table('tenant_integrations')->where('tenant_id', $tenant->id)->where('kind', 'email')->delete();

        return $this->settings($request);
    }

    public function testEmail(Request $request)
    {
        abort_unless($request->user()->allows('settings.edit'), 403, 'Tu cuenta no tiene permiso para cambiar la configuración de la empresa.');
        $tenant = $this->tenant($request);
        $to = $request->validate(['to' => 'required|email|max:190'], ['to.required' => 'Escribe a qué correo enviar la prueba.', 'to.email' => 'Ese correo no es válido.'])['to'];
        $config = Messaging::emailConfig($tenant->id);

        if (!$config) {
            throw ValidationException::withMessages(['to' => 'Primero guarda los datos del correo.']);
        }

        if ($config['provider'] === 'simulated') {
            return response()->json(['ok' => true, 'message' => 'Estás en "Probar sin enviar": la prueba no sale. Cuando configures un correo real, este botón envía un correo de verdad.'] + $this->settings($request)->getData(true));
        }

        abort_unless(config('app.own_email_enabled'), 422, 'Enviar desde el correo de tu empresa está en construcción. Por ahora puedes usar "Probar sin enviar".');

        $company = Messaging::companyName($tenant);
        $ok = true;

        try {
            Messaging::send($config, $to, null, "Correo de prueba de {$company}", "Hola:\n\nEste es un correo de prueba. Si lo recibiste, tu empresa ya puede enviar mensajes a sus clientes.\n\n{$company}", $tenant->id);
            $message = "Listo: se envió un correo de prueba a {$to}. Revisa su bandeja de entrada (y la de spam).";
        } catch (\Throwable $e) {
            $ok = false;
            $message = Messaging::explain($e);
        }

        $row = Messaging::integration($tenant->id);
        $settings = (is_string($row->settings) ? json_decode($row->settings, true) : (array) $row->settings) + [];
        $settings['test_ok'] = $ok;
        $settings['tested_at'] = now()->toIso8601String();
        $settings['test_message'] = $message;
        DB::table('tenant_integrations')->where('id', $row->id)->update(['settings' => json_encode($settings)]);

        return response()->json(['ok' => $ok, 'message' => $message] + $this->settings($request)->getData(true));
    }

    public function saveNotice(Request $request, string $kind)
    {
        abort_unless($request->user()->allows('settings.edit'), 403, 'Tu cuenta no tiene permiso para cambiar la configuración de la empresa.');
        abort_unless(isset(Messaging::KINDS[$kind]), 404);
        $tenant = $this->tenant($request);

        $validated = $request->validate([
            'enabled'        => 'required|boolean',
            'subject'        => 'nullable|string|max:200',
            'body'           => 'nullable|string|max:20000',
            'options'        => 'nullable|array',
            'options.hours'  => 'nullable|integer|min:1|max:168',
            'options.second' => 'nullable|boolean',
            'options.days'   => 'nullable|integer|min:0|max:60',
        ], [
            'options.hours.min' => 'El recordatorio sale al menos 1 hora antes.',
            'options.hours.max' => 'El recordatorio sale como máximo 7 días (168 horas) antes.',
            'options.days.max'  => 'Como máximo 60 días antes.',
        ]);

        $def = Messaging::KINDS[$kind];
        $subject = trim((string) ($validated['subject'] ?? ''));
        $body = RichText::normalize($validated['body'] ?? '');
        $options = array_intersect_key($validated['options'] ?? [], $def['options'] ?? []);

        DB::table('message_settings')->upsert([[
            'id'         => (string) Str::uuid(),
            'tenant_id'  => $tenant->id,
            'kind'       => $kind,
            'enabled'    => $validated['enabled'],
            'subject'    => $subject !== '' && $subject !== $def['subject'] ? $subject : null,
            'body'       => $body !== '' && !RichText::same($body, $def['body']) ? $body : null,
            'options'    => json_encode($options),
            'updated_by' => $request->user()->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]], ['tenant_id', 'kind'], ['enabled', 'subject', 'body', 'options', 'updated_by', 'updated_at']);

        return response()->json(Messaging::settings($tenant->id)[$kind]);
    }

    public function resetNotice(Request $request, string $kind)
    {
        abort_unless($request->user()->allows('settings.edit'), 403, 'Tu cuenta no tiene permiso para cambiar la configuración de la empresa.');
        abort_unless(isset(Messaging::KINDS[$kind]), 404);
        $tenant = $this->tenant($request);
        DB::table('message_settings')->where('tenant_id', $tenant->id)->where('kind', $kind)->update(['subject' => null, 'body' => null, 'updated_at' => now()]);

        return response()->json(Messaging::settings($tenant->id)[$kind]);
    }

    private function designInput(Request $request): array
    {
        return $request->validate([
            'color'     => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'show_name' => 'required|boolean',
            'footer'    => 'nullable|string|max:500',
        ], [
            'color.required' => 'Elige el color de tus correos.',
            'color.regex'    => 'Escribe el color como # y seis letras o números, por ejemplo #3F768C.',
            'footer.max'     => 'El pie del correo puede tener hasta 500 letras.',
        ]);
    }

    public function saveDesign(Request $request)
    {
        abort_unless($request->user()->allows('settings.edit'), 403, 'Tu cuenta no tiene permiso para cambiar la configuración de la empresa.');
        $tenant = $this->tenant($request);
        EmailDesign::save($tenant, $this->designInput($request));

        return $this->settings($request);
    }

    public function uploadLogo(Request $request)
    {
        abort_unless($request->user()->allows('settings.edit'), 403, 'Tu cuenta no tiene permiso para cambiar la configuración de la empresa.');
        $tenant = $this->tenant($request);
        $file = $request->validate(['logo' => 'required|file|mimes:png,jpg,jpeg,webp|max:1024'], [
            'logo.required' => 'Elige la imagen de tu logo.',
            'logo.mimes'    => 'El logo debe ser una imagen PNG, JPG o WEBP.',
            'logo.max'      => 'El logo pesa más de 1 MB. Usa una imagen más liviana.',
        ])['logo'];

        abort_unless(@getimagesize($file->getRealPath()), 422, 'Ese archivo no es una imagen válida.');
        EmailDesign::storeLogo($tenant, $file);

        return $this->settings($request);
    }

    public function removeLogo(Request $request)
    {
        abort_unless($request->user()->allows('settings.edit'), 403, 'Tu cuenta no tiene permiso para cambiar la configuración de la empresa.');
        $tenant = $this->tenant($request);
        EmailDesign::removeLogo($tenant);

        return $this->settings($request);
    }

    public function preview(Request $request)
    {
        $tenant = $this->tenant($request);
        $validated = $request->validate([
            'color'     => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'show_name' => 'nullable|boolean',
            'footer'    => 'nullable|string|max:500',
            'subject'   => 'nullable|string|max:200',
            'body'      => 'nullable|string|max:20000',
        ]);

        $design = EmailDesign::of($tenant);

        foreach (['color', 'show_name', 'footer'] as $key) {
            if ($request->has($key)) {
                $design[$key] = $validated[$key] ?? ($key === 'footer' ? '' : $design[$key]);
            }
        }

        $vars = EmailDesign::SAMPLE + ['Empresa' => $design['company']];
        $body = trim((string) ($validated['body'] ?? '')) ?: EmailDesign::SAMPLE_BODY;

        return response()->json([
            'subject' => Messaging::render((string) ($validated['subject'] ?? ''), $vars),
            'html'    => EmailDesign::render($tenant, RichText::fill(RichText::normalize($body), $vars), $design),
        ]);
    }

    public function logo(string $tenant)
    {
        abort_unless(\Illuminate\Support\Str::isUuid($tenant), 404);
        $path = EmailDesign::logoPath($tenant);
        abort_unless($path, 404);

        return response()->file(\Illuminate\Support\Facades\Storage::disk('local')->path($path), ['Cache-Control' => 'public, max-age=86400']);
    }

    public function sendPaymentLink(Request $request, string $link)
    {
        $tenant = $this->tenant($request);
        abort_unless(PaymentLinks::ready(), 503, PaymentLinks::NOT_READY);
        $row = DB::table('payment_links')->where('tenant_id', $tenant->id)->where('id', $link)->first();
        abort_unless($row, 404);
        Access::assertBranch($request->user(), $row->branch_id);

        if ($row->status !== 'pending') {
            throw ValidationException::withMessages(['link' => 'Este link ya no está esperando pago.']);
        }

        $sale = $row->sale_id ? DB::table('sales')->where('id', $row->sale_id)->first() : null;
        $customer = Customer::where('tenant_id', $tenant->id)->find($row->customer_id ?? $sale?->customer_id);

        if (!$customer) {
            throw ValidationException::withMessages(['link' => 'Esta venta no tiene cliente: elige uno para enviarle el link.']);
        }

        $message = Messaging::queue($tenant, 'payment_link', $customer, [
            'Detalle' => $row->description ?? ($sale ? "la venta {$sale->code}" : 'tu compra'),
            'Monto'   => Currency::format((float) $row->amount, $row->currency),
            'Link'    => PaymentLinks::url($row->token),
            'Vence'   => Messaging::longDate(Carbon::parse($row->expires_at)->setTimezone($this->timezone($tenant))) . ' a las ' . \App\Support\Packages::hour(Carbon::parse($row->expires_at)->setTimezone($this->timezone($tenant))->format('H:i')),
        ], ['branch_id' => $row->branch_id, 'entity_type' => 'payment_link', 'entity_id' => $row->id, 'user' => $request->user(), 'force' => true]);

        Messaging::process($tenant->id, 5);

        return response()->json($this->one($message->id));
    }

    public function sendReceipt(Request $request, string $sale)
    {
        $tenant = $this->tenant($request);
        $row = DB::table('sales')->where('tenant_id', $tenant->id)->where('id', $sale)->first();
        abort_unless($row, 404);
        Access::assertBranch($request->user(), $row->branch_id);

        if (!$row->customer_id) {
            throw ValidationException::withMessages(['sale' => 'Esta venta no tiene cliente: no hay a quién enviarle el comprobante.']);
        }

        if (!in_array($row->status, ['paid', 'partially_refunded'], true)) {
            throw ValidationException::withMessages(['sale' => 'El comprobante se envía cuando la venta está pagada.']);
        }

        $message = Messaging::receipt($tenant, $row, $request->user(), true);
        abort_unless($message, 422, 'No se pudo armar el mensaje.');
        Messaging::process($tenant->id, 5);

        return response()->json($this->one($message->id));
    }

    public static function consentsOf(string $customerId): array
    {
        $rows = DB::table('customer_consents')->where('customer_id', $customerId)->whereIn('channel', ['email', 'whatsapp'])->get()->keyBy('channel');
        $iso = fn ($value) => $value ? Carbon::parse($value)->toIso8601String() : null;

        return collect(['email', 'whatsapp'])->mapWithKeys(fn ($channel) => [$channel => [
            'granted' => (bool) ($rows[$channel]->granted ?? false),
            'source'  => $rows[$channel]->source ?? null,
            'at'      => $iso(($rows[$channel] ?? null)?->granted ? $rows[$channel]->granted_at : (($rows[$channel] ?? null)?->revoked_at)),
        ]])->all();
    }

    public static function saveConsents(string $tenantId, string $customerId, array $values, string $source): array
    {
        foreach (['email', 'whatsapp'] as $channel) {
            if (!array_key_exists($channel, $values)) {
                continue;
            }

            $granted = (bool) $values[$channel];
            $current = DB::table('customer_consents')->where('customer_id', $customerId)->where('channel', $channel)->first();

            if ($current && (bool) $current->granted === $granted) {
                continue;
            }

            DB::table('customer_consents')->upsert([[
                'id'          => $current->id ?? (string) Str::uuid(),
                'tenant_id'   => $tenantId,
                'customer_id' => $customerId,
                'channel'     => $channel,
                'granted'     => $granted,
                'source'      => $source,
                'granted_at'  => $granted ? now() : ($current->granted_at ?? null),
                'revoked_at'  => $granted ? null : now(),
                'created_at'  => now(),
                'updated_at'  => now(),
            ]], ['customer_id', 'channel'], ['granted', 'source', 'granted_at', 'revoked_at', 'updated_at']);
        }

        return self::consentsOf($customerId);
    }

    public function consents(Request $request, string $customer)
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 403);
        $row = Customer::where('tenant_id', $tenant->id)->findOrFail($customer);
        $validated = $request->validate(['email' => 'nullable|boolean', 'whatsapp' => 'nullable|boolean']);

        return response()->json(['consents' => self::saveConsents($tenant->id, $row->id, $validated, 'staff')]);
    }
}
