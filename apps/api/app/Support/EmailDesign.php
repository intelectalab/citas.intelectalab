<?php

namespace App\Support;

use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Support\Facades\DB;

class EmailDesign
{
    public const COLORS = ['#3F768C', '#022340', '#0F766E', '#15803D', '#B45309', '#BE123C', '#7C3AED', '#334155'];

    public const LOGO_PLACEHOLDER = '__LOGO_SRC__';

    public const SAMPLE = [
        'Nombre'      => 'Juliana',
        'Servicio'    => 'Control Pediátrico',
        'Fecha'       => 'martes 20 de octubre',
        'Hora'        => '10:00 a. m.',
        'Profesional' => 'Dra. María Gómez',
        'Sede'        => 'Sede Central - Miraflores',
        'Código'      => 'C-0248',
        'Paquete'     => 'Paquete 5 controles',
        'Sesiones'    => '2 sesiones',
        'Vence'       => 'viernes 30 de octubre',
        'Monto'       => 'S/ 60.00',
        'Detalle'     => '• Control Pediátrico: S/ 60.00',
        'Comprobante' => 'B001-00000123',
        'Link'        => 'https://citas.ejemplo.com/pay/ABC123',
        'Portal'      => 'https://citas.ejemplo.com/login',
        'Correo'      => 'juliana@correo.com',
        'Control'     => 'control de vacunas',
        'Cupón'       => 'VUELVE10',
    ];

    public const SAMPLE_BODY = '<h2>¡Hola, [Nombre]!</h2><p>Así se verán tus correos: con tu logo, tu color y tu pie de página.</p><p>Puedes escribir en <strong>negrita</strong>, <em>cursiva</em> o <u>subrayado</u>, y agregar listas:</p><ul><li><p>Tu cita: [Fecha] a las [Hora]</p></li><li><p>Con: [Profesional]</p></li></ul><p><a href="[Portal]">Reserva en nuestro portal</a></p>';

    public static function of(Tenant $tenant): array
    {
        $tenant->loadMissing(['settings', 'businessType']);
        $rules = $tenant->settings?->rules ?? [];
        $default = self::defaultFooter($tenant);

        return [
            'color'          => Branding::primary($tenant) ?? $rules['email.color'] ?? $tenant->businessType?->color ?? self::COLORS[0],
            'color_from_brand' => Branding::primary($tenant) !== null,
            'show_name'      => (bool) ($rules['email.show_name'] ?? true),
            'footer'         => array_key_exists('email.footer', $rules) ? (string) $rules['email.footer'] : $default,
            'default_footer' => $default,
            'logo_url'       => self::logoPath($tenant->id) ? $tenant->settings?->logo_url : null,
            'company'        => Messaging::companyName($tenant),
        ];
    }

    public static function defaultFooter(Tenant $tenant): string
    {
        $company = DB::table('companies')->where('tenant_id', $tenant->id)->orderBy('created_at')->first();
        $place = collect([$company->address ?? null, $company->district ?? null])->filter()->implode(', ');

        return collect([Messaging::companyName($tenant), $place, !empty($company?->phone) ? 'Teléfono: ' . $company->phone : null])->filter()->implode("\n");
    }

    public static function save(Tenant $tenant, array $values): void
    {
        $settings = $tenant->settings ?? new TenantSetting(['tenant_id' => $tenant->id]);
        $settings->rules = array_merge($settings->rules ?? [], [
            'email.color'     => strtoupper((string) ($values['color'] ?? ($settings->rules['email.color'] ?? self::COLORS[0]))),
            'email.show_name' => (bool) $values['show_name'],
            'email.footer'    => trim((string) ($values['footer'] ?? '')),
        ]);
        $settings->save();
        $tenant->setRelation('settings', $settings);
    }

    public static function logoPath(string $tenantId): ?string
    {
        return Branding::logoPath($tenantId, 'light');
    }

    public static function render(Tenant $tenant, string $body, ?array $design = null, ?string $logoSrc = null): string
    {
        $design ??= self::of($tenant);
        $color = preg_match('/^#[0-9A-Fa-f]{6}$/', (string) $design['color']) ? $design['color'] : self::COLORS[0];
        $logo = $logoSrc !== null ? ($logoSrc ?: null) : $design['logo_url'];
        $name = $design['show_name'] ? e($design['company']) : '';

        $header = '';

        if ($logo || $name !== '') {
            $header = '<div style="padding:24px 28px 0"><table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>'
                . ($logo ? '<td style="vertical-align:middle"><img src="' . e($logo) . '" alt="' . e($design['company']) . '" style="display:block;max-height:56px;max-width:200px;height:auto;border:0"></td>' : '')
                . ($name !== '' ? '<td style="vertical-align:middle;' . ($logo ? 'padding-left:14px;' : '') . 'font-size:17px;font-weight:bold;color:#0f172a">' . $name . '</td>' : '')
                . '</tr></table></div>';
        }

        $footer = trim((string) $design['footer']);

        return '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"></head>'
            . '<body style="margin:0;padding:24px 12px;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#1f2937">'
            . '<div style="max-width:560px;margin:0 auto;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e2e8f0">'
            . '<div style="height:6px;background:' . $color . ';font-size:0;line-height:0">&nbsp;</div>'
            . $header
            . '<div style="padding:20px 28px 10px;font-size:15px;line-height:1.6">' . RichText::email($body, $color) . '</div>'
            . ($footer !== '' ? '<div style="padding:14px 28px 22px;border-top:1px solid #e2e8f0;font-size:12px;line-height:1.5;color:#64748b">' . nl2br(e($footer), false) . '</div>' : '')
            . '</div></body></html>';
    }
}
