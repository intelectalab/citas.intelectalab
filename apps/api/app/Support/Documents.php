<?php

namespace App\Support;

use App\Models\AuditEvent;
use App\Models\Company;
use App\Models\Professional;
use App\Models\Tenant;
use App\Models\TenantSetting;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class Documents
{
    public const FOOTER_MAX = 300;

    public const TERMS_MAX = 1500;

    public const DEFAULT_TERMS = "Los precios están en la moneda indicada.\nEste presupuesto es válido hasta la fecha que figura arriba. Si el tratamiento cambia, te entregaremos uno nuevo.\nPuedes pagarlo completo o en las cuotas acordadas.";

    private static function stored(Tenant $tenant): array
    {
        $stored = Branding::ready() ? Branding::stored($tenant) : [];

        return is_array($stored['documents'] ?? null) ? $stored['documents'] : [];
    }

    public static function autoFooter(Tenant $tenant): string
    {
        $company = Company::where('tenant_id', $tenant->id)->orderBy('created_at')->first();
        $place = collect([$company?->address, $company?->district])->map(fn ($v) => trim((string) $v))->filter()->implode(', ');
        $phone = trim((string) $company?->phone);

        return collect([trim((string) ($company?->trade_name ?: $tenant->name)), $place, $phone ? "Teléfono {$phone}" : null])->filter()->implode(' · ');
    }

    public static function present(Tenant $tenant): array
    {
        $stored = self::stored($tenant);
        $footer = $stored['footer'] ?? null;
        $terms = $stored['quote_terms'] ?? null;

        return [
            'show_logo'      => ($stored['show_logo'] ?? true) !== false,
            'footer'         => is_string($footer) ? $footer : self::autoFooter($tenant),
            'footer_auto'    => !is_string($footer),
            'auto_footer'    => self::autoFooter($tenant),
            'quote_terms'    => is_string($terms) ? $terms : self::DEFAULT_TERMS,
            'default_terms'  => self::DEFAULT_TERMS,
            'signature'      => [
                'name'      => (string) ($stored['signature_name'] ?? ''),
                'role'      => (string) ($stored['signature_role'] ?? ''),
                'has_image' => (bool) self::signaturePath($tenant->id),
                'version'   => $stored['signature_version'] ?? null,
            ],
        ];
    }

    public static function save(Request $request, Tenant $tenant, array $values): array
    {
        $before = self::present($tenant);
        $stored = self::stored($tenant);
        $clean = fn ($text, int $max) => mb_substr(trim(str_replace(["\r\n", "\r"], "\n", (string) $text)), 0, $max);

        $stored['show_logo'] = (bool) ($values['show_logo'] ?? true);

        if (($values['footer_auto'] ?? false) === true) {
            unset($stored['footer']);
        } else {
            $stored['footer'] = $clean($values['footer'] ?? '', self::FOOTER_MAX);
        }

        $terms = $clean($values['quote_terms'] ?? '', self::TERMS_MAX);
        if ($terms === self::DEFAULT_TERMS) {
            unset($stored['quote_terms']);
        } else {
            $stored['quote_terms'] = $terms;
        }

        $stored['signature_name'] = $clean($values['signature_name'] ?? '', 120);
        $stored['signature_role'] = $clean($values['signature_role'] ?? '', 120);

        self::write($tenant, $stored);
        $after = self::present($tenant);

        $was = ['logo' => $before['show_logo'], 'pie' => $before['footer'], 'condiciones' => $before['quote_terms'], 'firma' => trim($before['signature']['name'] . ' · ' . $before['signature']['role'], ' ·')];
        $now = ['logo' => $after['show_logo'], 'pie' => $after['footer'], 'condiciones' => $after['quote_terms'], 'firma' => trim($after['signature']['name'] . ' · ' . $after['signature']['role'], ' ·')];
        $changed = array_keys(array_filter($now, fn ($value, $key) => $was[$key] !== $value, ARRAY_FILTER_USE_BOTH));

        if ($changed) {
            AuditEvent::record($request, 'tenant.documents_changed', $tenant, array_intersect_key($was, array_flip($changed)), array_intersect_key($now, array_flip($changed)));
        }

        return $after;
    }

    private static function write(Tenant $tenant, array $documents): void
    {
        $settings = $tenant->settings ?? new TenantSetting(['tenant_id' => $tenant->id]);
        $stored = Branding::stored($tenant);
        $stored['documents'] = $documents;
        $settings->setAttribute('branding', $stored);
        $settings->save();
        $tenant->setRelation('settings', $settings);
    }

    public static function signaturePath(string $tenantId): ?string
    {
        foreach (Storage::disk('local')->files("branding/{$tenantId}") as $file) {
            if (str_starts_with(basename($file), 'signature.')) {
                return $file;
            }
        }

        return null;
    }

    public static function storeSignature(Request $request, Tenant $tenant, UploadedFile $file): void
    {
        while ($path = self::signaturePath($tenant->id)) {
            Storage::disk('local')->delete($path);
        }

        Storage::disk('local')->putFileAs("branding/{$tenant->id}", $file, 'signature.' . strtolower($file->extension() ?: 'png'));
        $stored = self::stored($tenant);
        $stored['signature_version'] = now()->timestamp;
        self::write($tenant, $stored);
        AuditEvent::record($request, 'tenant.signature_changed', $tenant, null, ['firma' => 'Imagen de firma y sello']);
    }

    public static function removeSignature(Request $request, Tenant $tenant): void
    {
        while ($path = self::signaturePath($tenant->id)) {
            Storage::disk('local')->delete($path);
        }

        AuditEvent::record($request, 'tenant.signature_removed', $tenant, ['firma' => 'Imagen de firma y sello'], null);
    }

    public static function professionalSignaturePath(Professional $professional): ?string
    {
        foreach (Storage::disk('local')->files("branding/{$professional->tenant_id}/signatures") as $file) {
            if (str_starts_with(basename($file), $professional->id . '.')) {
                return $file;
            }
        }

        return null;
    }

    public static function storeProfessionalSignature(Request $request, Professional $professional, UploadedFile $file): void
    {
        while ($path = self::professionalSignaturePath($professional)) {
            Storage::disk('local')->delete($path);
        }

        Storage::disk('local')->putFileAs("branding/{$professional->tenant_id}/signatures", $file, $professional->id . '.' . strtolower($file->extension() ?: 'png'));
        $professional->forceFill(['signature_url' => "/professionals/{$professional->id}/signature?v=" . now()->timestamp])->save();
        AuditEvent::record($request, 'professional.signature_changed', $professional->tenant, null, ['profesional' => $professional->display_name]);
    }

    public static function removeProfessionalSignature(Request $request, Professional $professional): void
    {
        while ($path = self::professionalSignaturePath($professional)) {
            Storage::disk('local')->delete($path);
        }

        $professional->forceFill(['signature_url' => null])->save();
        AuditEvent::record($request, 'professional.signature_removed', $professional->tenant, ['profesional' => $professional->display_name], null);
    }

    public static function dataUri(?string $path): ?string
    {
        if (!$path || !Storage::disk('local')->exists($path)) {
            return null;
        }

        $absolute = Storage::disk('local')->path($path);
        $type = @getimagesize($absolute)['mime'] ?? null;

        if ($type === 'image/webp') {
            if (!function_exists('imagecreatefromwebp') || !($image = @imagecreatefromwebp($absolute))) {
                return null;
            }
            imagesavealpha($image, true);
            ob_start();
            imagepng($image);
            $bytes = ob_get_clean();
            imagedestroy($image);

            return 'data:image/png;base64,' . base64_encode($bytes);
        }

        return in_array($type, ['image/png', 'image/jpeg'], true)
            ? "data:{$type};base64," . base64_encode(Storage::disk('local')->get($path))
            : null;
    }

    public static function forPdf(Tenant $tenant): array
    {
        $look = self::present($tenant);

        return [
            'logo'      => $look['show_logo'] ? self::dataUri(Branding::logoPath($tenant->id, 'light')) : null,
            'color'     => Branding::primary($tenant) ?? $tenant->businessType?->color ?? Branding::DEFAULTS['primary'],
            'footer'    => $look['footer'],
            'terms'     => $look['quote_terms'],
            'signature' => [
                'image' => self::dataUri(self::signaturePath($tenant->id)),
                'name'  => $look['signature']['name'],
                'role'  => $look['signature']['role'],
            ],
        ];
    }

    public static function forReceipt(Tenant $tenant): array
    {
        $look = self::present($tenant);

        return [
            'logo_url' => $look['show_logo'] ? Branding::present($tenant)['logo_url'] : null,
            'footer'   => $look['footer'],
        ];
    }

    public static function pdf(string $html): string
    {
        $options = new \Dompdf\Options();
        $options->set('defaultFont', 'DejaVu Sans');
        $options->set('isRemoteEnabled', false);

        $pdf = new \Dompdf\Dompdf($options);
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        $pdf->getCanvas()->page_text(520, 815, 'Página {PAGE_NUM} de {PAGE_COUNT}', null, 7, [0.58, 0.64, 0.72]);

        return $pdf->output();
    }
}
