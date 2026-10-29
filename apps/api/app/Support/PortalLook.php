<?php

namespace App\Support;

use App\Models\AuditEvent;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PortalLook
{
    public const TEXTS = [
        'subtitle'        => 60,
        'welcome'         => 300,
        'book_title'      => 60,
        'book_text'       => 160,
        'no_booking_text' => 220,
        'profile_note'    => 220,
    ];

    public const PRIVACY_MAX = 30000;

    public const MISSING = '(dato por completar)';

    public const TOKENS = [
        'Empresa'              => 'Nombre comercial',
        'Razón social'         => 'Razón social',
        'RUC'                  => 'RUC',
        'Dirección'            => 'Dirección',
        'Teléfono'             => 'Teléfono',
        'Correo de privacidad' => 'Correo para consultas sobre datos',
    ];

    public static function defaults(Tenant $tenant): array
    {
        $client = mb_strtolower($tenant->vocabulary()['client'] ?? 'cliente');

        return [
            'subtitle'        => "Portal de {$client}",
            'welcome'         => '',
            'book_title'      => 'Reserva cuando quieras',
            'book_text'       => 'Elige el servicio, el día y la hora que te acomoden.',
            'no_booking_text' => "Para agendar una cita, comunícate con {$tenant->name}. Aquí verás las que tengas.",
            'profile_note'    => "Para corregir tu nombre, tu correo o tu documento, comunícate con {$tenant->name}.",
        ];
    }

    public static function template(): string
    {
        return implode('', [
            '<h2>Política de privacidad</h2>',
            '<p>En [Empresa] cuidamos tus datos personales. Aquí te contamos qué datos usamos, para qué y cómo puedes ejercer tus derechos, según la Ley N.° 29733, Ley de Protección de Datos Personales, y su reglamento.</p>',
            '<h3>Quién se encarga de tus datos</h3>',
            '<p>[Razón social], con RUC [RUC] y domicilio en [Dirección].</p>',
            '<h3>Qué datos usamos</h3>',
            '<ul><li><p>Tus datos de contacto: nombre, documento de identidad, correo y celular.</p></li>',
            '<li><p>Tus citas, compras, pagos y comprobantes.</p></li>',
            '<li><p>La información que se registre al atenderte, cuando el servicio lo requiera. Si incluye datos de salud, se trata como dato sensible: con más cuidado y solo la ve el personal que te atiende.</p></li></ul>',
            '<h3>Para qué los usamos</h3>',
            '<ul><li><p>Reservar, confirmar y recordarte tus citas.</p></li>',
            '<li><p>Atenderte y llevar tu historial.</p></li>',
            '<li><p>Cobrarte y emitir tus comprobantes.</p></li>',
            '<li><p>Enviarte promociones, solo si lo aceptas. Puedes dejar de recibirlas cuando quieras desde "Mis datos".</p></li></ul>',
            '<h3>Con quién los compartimos</h3>',
            '<p>No vendemos tus datos. Solo los usan los proveedores que nos ayudan a darte el servicio (el sistema de reservas, el envío de mensajes y el cobro en línea) y las autoridades cuando la ley lo exige, por ejemplo SUNAT para tus comprobantes.</p>',
            '<h3>Cuánto tiempo los guardamos</h3>',
            '<p>Mientras te atendamos y, después, el tiempo que exijan las normas, por ejemplo las de comprobantes o de historias clínicas.</p>',
            '<h3>Tus derechos</h3>',
            '<p>Puedes pedir ver tus datos, corregirlos, eliminarlos u oponerte a que los usemos. Escríbenos a [Correo de privacidad], llámanos al [Teléfono] o acércate a cualquiera de nuestras sedes. Te responderemos en los plazos de ley. Si no quedas conforme, puedes acudir a la Autoridad Nacional de Protección de Datos Personales del Ministerio de Justicia y Derechos Humanos.</p>',
            '<h3>Cómo los protegemos</h3>',
            '<p>Tus datos se guardan en un sistema al que se entra con usuario y contraseña. Cada persona del equipo ve solo lo que necesita para su trabajo.</p>',
            '<h3>Cambios</h3>',
            '<p>Si cambiamos esta política, lo verás aquí con su fecha.</p>',
        ]);
    }

    private static function stored(Tenant $tenant): array
    {
        $stored = Branding::ready() ? Branding::stored($tenant) : [];

        return [
            'portal'  => is_array($stored['portal'] ?? null) ? $stored['portal'] : [],
            'privacy' => is_array($stored['privacy'] ?? null) ? $stored['privacy'] : [],
        ];
    }

    private static function company(Tenant $tenant): ?Company
    {
        return Company::where('tenant_id', $tenant->id)->orderBy('created_at')->first();
    }

    public static function companyData(Tenant $tenant): array
    {
        $company = self::company($tenant);
        $place = collect([$company?->address, $company?->district, $company?->department])->map(fn ($v) => trim((string) $v))->filter()->implode(', ');

        return [
            'Empresa'      => trim((string) ($company?->trade_name ?: $tenant->name)),
            'Razón social' => trim((string) ($company?->legal_name ?: $company?->trade_name ?: '')),
            'RUC'          => trim((string) $company?->tax_id),
            'Dirección'    => $place,
            'Teléfono'     => trim((string) $company?->phone),
        ];
    }

    public static function present(Tenant $tenant): array
    {
        $stored = self::stored($tenant);
        $defaults = self::defaults($tenant);
        $texts = [];

        foreach ($defaults as $key => $value) {
            $custom = $stored['portal'][$key] ?? null;
            $texts[$key] = is_string($custom) ? $custom : $value;
        }

        $privacy = $stored['privacy'];
        $company = self::companyData($tenant);
        $email = (string) ($privacy['email'] ?? '');
        $values = $company + ['Correo de privacidad' => $email];
        $body = is_string($privacy['body'] ?? null) && trim($privacy['body']) !== '' ? $privacy['body'] : self::template();
        $used = array_filter(array_keys(self::TOKENS), fn ($token) => str_contains($body, "[{$token}]"));

        return [
            'texts'        => $texts,
            'defaults'     => $defaults,
            'show_contact' => ($stored['portal']['show_contact'] ?? true) !== false,
            'privacy'      => [
                'body'       => $body,
                'custom'     => isset($privacy['body']),
                'email'      => $email,
                'updated_at' => $privacy['updated_at'] ?? null,
                'template'   => self::template(),
                'tokens'     => collect(self::TOKENS)->map(fn ($label, $token) => ['token' => "[{$token}]", 'label' => $label])->values()->all(),
                'company'    => $values,
                'missing'    => array_values(array_filter($used, fn ($token) => trim((string) ($values[$token] ?? '')) === '')),
            ],
        ];
    }

    public static function privacyHtml(Tenant $tenant): array
    {
        $look = self::present($tenant);
        $values = array_map(fn ($v) => trim((string) $v) === '' ? self::MISSING : $v, $look['privacy']['company']);

        return [
            'html'       => RichText::fill(RichText::toHtml($look['privacy']['body']), $values),
            'updated_at' => $look['privacy']['updated_at'],
        ];
    }

    public static function forPortal(Tenant $tenant): array
    {
        $look = self::present($tenant);
        $contact = null;

        if ($look['show_contact']) {
            $company = self::companyData($tenant);
            $branches = Branch::where('tenant_id', $tenant->id)->where('status', 'active')->orderBy('name')
                ->get(['id', 'name', 'address', 'district', 'department', 'phone'])
                ->map(fn (Branch $b) => [
                    'id'      => $b->id,
                    'name'    => $b->name,
                    'address' => collect([$b->address, $b->district, $b->department])->map(fn ($v) => trim((string) $v))->filter()->implode(', ') ?: null,
                    'phone'   => trim((string) $b->phone) ?: null,
                ]);

            $contact = [
                'phone'    => $company['Teléfono'] ?: null,
                'email'    => $look['privacy']['email'] ?: null,
                'branches' => $branches->filter(fn ($b) => $b['address'] || $b['phone'])->values()->all(),
            ];

            if (!$contact['phone'] && !$contact['email'] && !$contact['branches']) {
                $contact = null;
            }
        }

        return [
            'texts'   => $look['texts'],
            'contact' => $contact,
        ];
    }

    private static function write(Tenant $tenant, string $part, array $values): void
    {
        $settings = $tenant->settings ?? new TenantSetting(['tenant_id' => $tenant->id]);
        $stored = Branding::stored($tenant);
        $stored[$part] = $values;
        $settings->setAttribute('branding', $stored);
        $settings->save();
        $tenant->setRelation('settings', $settings);
    }

    public static function saveTexts(Request $request, Tenant $tenant, array $values): array
    {
        $before = self::present($tenant);
        $defaults = self::defaults($tenant);
        $portal = [];

        foreach ($defaults as $key => $default) {
            $value = trim(preg_replace('/\s+/u', ' ', (string) ($values[$key] ?? '')));
            if ($key !== 'welcome' && $value === '') {
                continue;
            }
            if ($value !== $default) {
                $portal[$key] = $value;
            }
        }

        if (array_key_exists('show_contact', $values) && !$values['show_contact']) {
            $portal['show_contact'] = false;
        }

        self::write($tenant, 'portal', $portal);
        $after = self::present($tenant);

        $was = $before['texts'] + ['show_contact' => $before['show_contact']];
        $now = $after['texts'] + ['show_contact' => $after['show_contact']];
        $changed = array_keys(array_filter($now, fn ($value, $key) => $was[$key] !== $value, ARRAY_FILTER_USE_BOTH));

        if ($changed) {
            AuditEvent::record($request, 'tenant.portal_texts_changed', $tenant, array_intersect_key($was, array_flip($changed)), array_intersect_key($now, array_flip($changed)));
        }

        return $after;
    }

    public static function savePrivacy(Request $request, Tenant $tenant, ?string $body, ?string $email): array
    {
        $before = self::present($tenant)['privacy'];
        $body = RichText::normalize($body);
        $custom = $body !== '' && !RichText::same($body, self::template()) ? $body : null;
        $email = trim((string) $email);

        $changed = ($custom !== null || $before['custom']) && !RichText::same($custom ?? self::template(), $before['body']);
        $emailChanged = $email !== $before['email'];

        if (!$changed && !$emailChanged) {
            return self::present($tenant);
        }

        self::write($tenant, 'privacy', array_filter([
            'body'       => $custom,
            'email'      => $email !== '' ? $email : null,
            'updated_at' => $changed ? Carbon::now()->toIso8601String() : $before['updated_at'],
        ], fn ($v) => $v !== null));

        AuditEvent::record(
            $request,
            'tenant.privacy_changed',
            $tenant,
            array_filter(['texto' => $changed ? ($before['custom'] ? 'Texto propio' : 'Modelo') : null, 'correo' => $emailChanged ? ($before['email'] ?: null) : null]),
            array_filter(['texto' => $changed ? ($custom !== null ? 'Texto propio' : 'Modelo') : null, 'correo' => $emailChanged ? ($email ?: 'Sin correo') : null]),
        );

        return self::present($tenant);
    }
}
