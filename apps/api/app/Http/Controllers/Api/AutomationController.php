<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\AppointmentFlow;
use App\Support\Audit;
use App\Support\Automations;
use App\Support\Coupons;
use App\Support\Currency;
use App\Support\Messaging;
use App\Support\RichText;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AutomationController extends Controller
{
    private function tenant(Request $request): Tenant
    {
        $tenant = $request->user()->tenant;
        abort_unless($tenant, 403);
        abort_unless(Automations::ready(), 503, Automations::NOT_READY);

        return $tenant->loadMissing('settings');
    }

    private function emailState(Tenant $tenant): array
    {
        $config = Messaging::emailConfig($tenant->id);

        return match (true) {
            !$config || !Messaging::emailReady($tenant->id) => ['state' => 'none', 'label' => 'Tu empresa aún no configura su correo: las automatizaciones no salen hasta que lo hagas en Configuración → Correo de tu empresa.'],
            $config['provider'] === 'simulated' => ['state' => 'simulated', 'label' => 'Tu correo está en "Probar sin enviar": las automatizaciones se arman pero no salen.'],
            default => ['state' => 'ok', 'label' => 'Las automatizaciones salen desde ' . ($config['from_address'] ?? 'el correo de tu empresa') . '.'],
        };
    }

    private function present(Tenant $tenant, object $rule, array $results, array $coupons): array
    {
        $key = $rule->trigger_event;
        $definition = Automations::TRIGGERS[$key];
        $coupon = $rule->coupon_id ? ($coupons[$rule->coupon_id] ?? DB::table('coupons')->where('id', $rule->coupon_id)->first(['id', 'code'])) : null;

        return [
            'id'           => $rule->id,
            'key'          => $key,
            'label'        => $definition['label'],
            'when'         => $definition['when'],
            'daily'        => $definition['daily'],
            'active'       => (bool) $rule->is_active,
            'subject'      => (string) $rule->subject,
            'body'         => RichText::toHtml((string) $rule->body),
            'coupon_id'    => $rule->coupon_id,
            'coupon_code'  => $coupon->code ?? null,
            'send_time'    => $rule->send_time ? substr((string) $rule->send_time, 0, 5) : $definition['time'],
            'delay_minutes' => (int) $rule->delay_minutes,
            'repeat_days'  => (int) $rule->repeat_days,
            'enabled_at'   => $rule->enabled_at ? \Illuminate\Support\Carbon::parse($rule->enabled_at)->setTimezone($tenant->zone())->format('Y-m-d\TH:i') : null,
            'results'      => $results[$rule->id] ?? ['sent_month' => 0, 'booked_month' => 0, 'last_sent_at' => null],
        ];
    }

    private function coupons(Tenant $tenant): array
    {
        $currency = Currency::of($tenant);

        return DB::table('coupons')->where('tenant_id', $tenant->id)->orderBy('code')->get()
            ->filter(fn ($c) => in_array(Coupons::state($c), ['valid', 'scheduled'], true))
            ->mapWithKeys(fn ($c) => [$c->id => (object) ['id' => $c->id, 'code' => $c->code, 'description' => $c->description, 'benefit' => Coupons::summary($c, 0, $currency)['benefit']]])
            ->all();
    }

    private function payload(Tenant $tenant): array
    {
        Automations::run($tenant);
        $results = Automations::results($tenant);
        $coupons = $this->coupons($tenant);

        return [
            'automations'   => collect(Automations::rules($tenant))->map(fn ($rule) => $this->present($tenant, $rule, $results, $coupons))->values(),
            'coupons'       => array_values($coupons),
            'variables'     => collect(Automations::VARIABLES)->map(fn ($label, $key) => ['token' => "[{$key}]", 'label' => $label])->values(),
            'email'         => $this->emailState($tenant),
            'inactive_days' => (int) AppointmentFlow::rule($tenant, 'crm.inactive_days'),
            'booked_days'   => Automations::BOOKED_DAYS,
        ];
    }

    public function index(Request $request)
    {
        return response()->json($this->payload($this->tenant($request)));
    }

    public function update(Request $request, string $automation)
    {
        $tenant = $this->tenant($request);
        Automations::rules($tenant);
        $rule = DB::table('automation_rules')->where('tenant_id', $tenant->id)->where('id', $automation)->first();
        abort_unless($rule && isset(Automations::TRIGGERS[$rule->trigger_event]), 404);
        $daily = Automations::TRIGGERS[$rule->trigger_event]['daily'];

        $validated = $request->validate([
            'active'        => 'required|boolean',
            'subject'       => 'sometimes|required|string|max:200',
            'body'          => 'sometimes|required|string|max:20000',
            'coupon_id'     => ['sometimes', 'nullable', 'uuid', Rule::exists('coupons', 'id')->where('tenant_id', $tenant->id)],
            'send_time'     => ['sometimes', 'nullable', 'date_format:H:i'],
            'delay_minutes' => 'sometimes|integer|min:0|max:43200',
            'repeat_days'   => 'sometimes|integer|min:0|max:3650',
        ], [
            'subject.required'          => 'Escribe el asunto del correo.',
            'body.required'             => 'Escribe el mensaje.',
            'send_time.date_format'     => 'Elige la hora de envío.',
            'delay_minutes.max'         => 'Puede salir hasta 30 días después.',
        ]);

        $subject = $validated['subject'] ?? $rule->subject;
        $body = array_key_exists('body', $validated) ? RichText::normalize($validated['body']) : $rule->body;
        $couponId = array_key_exists('coupon_id', $validated) ? $validated['coupon_id'] : $rule->coupon_id;

        if (!$couponId && (str_contains((string) $body, '[Cupón]') || str_contains((string) $subject, '[Cupón]'))) {
            throw ValidationException::withMessages(['coupon_id' => 'El mensaje usa el dato [Cupón], pero no elegiste un cupón. Elige uno o quita ese dato del mensaje.']);
        }

        if ($validated['active'] && !$rule->is_active && !Messaging::emailReady($tenant->id)) {
            throw ValidationException::withMessages(['active' => 'Antes de encender una automatización, configura el correo de tu empresa en Configuración → Correo de tu empresa.']);
        }

        $changes = [
            'is_active'     => $validated['active'],
            'subject'       => $subject,
            'body'          => $body,
            'coupon_id'     => $couponId,
            'send_time'     => $daily ? ($validated['send_time'] ?? $rule->send_time) : null,
            'delay_minutes' => $daily ? 0 : ($validated['delay_minutes'] ?? $rule->delay_minutes),
            'repeat_days'   => $validated['repeat_days'] ?? $rule->repeat_days,
            'enabled_at'    => $validated['active'] ? ($rule->is_active ? $rule->enabled_at : now()) : null,
            'updated_by'    => $request->user()->id,
            'updated_at'    => now(),
        ];

        DB::table('automation_rules')->where('id', $rule->id)->update($changes);

        $before = $this->auditable($rule);
        $after = $this->auditable((object) ($changes + ['trigger_event' => $rule->trigger_event]));
        $action = $validated['active'] !== (bool) $rule->is_active ? ($validated['active'] ? 'automation.enabled' : 'automation.disabled') : 'automation.updated';

        if ($before !== $after) {
            Audit::log($request, $action, [
                'tenant'       => $tenant->id,
                'entity_type'  => 'automation',
                'entity_id'    => $rule->id,
                'entity_label' => Automations::TRIGGERS[$rule->trigger_event]['label'],
                'before'       => array_diff_assoc($before, $after),
                'after'        => array_diff_assoc($after, $before),
            ]);
        }

        return response()->json($this->payload($tenant) + ['message' => $validated['active'] ? 'Automatización encendida.' : 'Automatización guardada.']);
    }

    private function auditable(object $rule): array
    {
        return [
            'active'        => $rule->is_active ? 'Sí' : 'No',
            'subject'       => (string) $rule->subject,
            'message'       => mb_substr(RichText::toText((string) $rule->body), 0, 200),
            'coupon'        => $rule->coupon_id ? (string) DB::table('coupons')->where('id', $rule->coupon_id)->value('code') : '—',
            'send_time'     => $rule->send_time ? substr((string) $rule->send_time, 0, 5) : '—',
            'delay_minutes' => (string) (int) $rule->delay_minutes,
            'repeat_days'   => (string) (int) $rule->repeat_days,
        ];
    }
}
