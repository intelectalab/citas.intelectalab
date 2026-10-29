<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\Access;
use App\Support\Branding;
use App\Support\EmailDesign;
use App\Support\PortalLook;
use App\Support\Documents;
use App\Support\Codes;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class BrandingController extends Controller
{
    private function tenant(Request $request, string $permission = 'settings.view'): Tenant
    {
        $user = $request->user();
        abort_unless($user?->tenant_id, 403);
        abort_unless(Branding::ready(), 503, Branding::NOT_READY);
        abort_unless($user->allows($permission), 403, Access::denial($permission, $user->tenant));

        return $user->tenant->loadMissing(['settings', 'businessType']);
    }

    private function payload(Request $request, Tenant $tenant): array
    {
        return [
            'branding' => Branding::present($tenant),
            'defaults' => Branding::DEFAULTS,
            'tenant'   => ['name' => $tenant->name],
            'email'    => EmailDesign::of($tenant),
            'portal'   => PortalLook::present($tenant),
            'documents' => Documents::present($tenant),
            'codes'    => Codes::present($tenant),
            'can_edit' => $request->user()->allows('settings.edit'),
        ];
    }

    public function savePortal(Request $request)
    {
        $tenant = $this->tenant($request, 'settings.edit');
        $rules = collect(PortalLook::TEXTS)->mapWithKeys(fn ($max, $key) => [$key => "nullable|string|max:{$max}"])->all();
        $messages = collect(PortalLook::TEXTS)->mapWithKeys(fn ($max, $key) => ["{$key}.max" => "Este texto puede tener hasta {$max} caracteres."])->all();

        $validated = $request->validate($rules + ['show_contact' => 'boolean'], $messages);
        PortalLook::saveTexts($request, $tenant, $validated);

        return response()->json($this->payload($request, $tenant->fresh(['settings', 'businessType'])));
    }

    public function savePrivacy(Request $request)
    {
        $tenant = $this->tenant($request, 'settings.edit');
        $validated = $request->validate([
            'body'  => 'nullable|string|max:' . PortalLook::PRIVACY_MAX,
            'email' => 'nullable|email|max:190',
        ], [
            'body.max'    => 'La política es demasiado larga.',
            'email.email' => 'Escribe un correo válido, por ejemplo datos@tuempresa.com.',
        ]);

        PortalLook::savePrivacy($request, $tenant, $validated['body'] ?? null, $validated['email'] ?? null);

        return response()->json($this->payload($request, $tenant->fresh(['settings', 'businessType'])));
    }

    public function saveDocuments(Request $request)
    {
        $tenant = $this->tenant($request, 'settings.edit');
        $validated = $request->validate([
            'show_logo'      => 'boolean',
            'footer_auto'    => 'boolean',
            'footer'         => 'nullable|string|max:' . Documents::FOOTER_MAX,
            'quote_terms'    => 'nullable|string|max:' . Documents::TERMS_MAX,
            'signature_name' => 'nullable|string|max:120',
            'signature_role' => 'nullable|string|max:120',
        ], [
            'footer.max'      => 'El pie puede tener hasta ' . Documents::FOOTER_MAX . ' caracteres.',
            'quote_terms.max' => 'Las condiciones pueden tener hasta ' . Documents::TERMS_MAX . ' caracteres.',
        ]);

        Documents::save($request, $tenant, $validated);

        return response()->json($this->payload($request, $tenant->fresh(['settings', 'businessType'])));
    }

    public function saveCodes(Request $request)
    {
        $tenant = $this->tenant($request, 'settings.edit');
        $validated = $request->validate(['prefixes' => 'required|array', 'prefixes.*' => 'nullable|string|max:10']);
        Codes::save($request, $tenant, $validated['prefixes']);

        return response()->json($this->payload($request, $tenant->fresh(['settings', 'businessType'])));
    }

    public static function validateSignature(Request $request): UploadedFile
    {
        $validated = $request->validate([
            'signature' => 'required|file|mimes:png,jpg,jpeg,webp|max:1024',
        ], [
            'signature.required' => 'Elige la imagen de la firma.',
            'signature.mimes'    => 'La firma debe ser una imagen PNG, JPG o WEBP.',
            'signature.max'      => 'La imagen pesa más de 1 MB. Usa una más liviana.',
        ]);

        $size = @getimagesize($validated['signature']->getRealPath());
        abort_unless($size, 422, 'Ese archivo no es una imagen válida.');
        abort_if($size[0] < 120 || $size[1] < 40, 422, "La imagen es muy pequeña ({$size[0]}×{$size[1]} px): usa una de al menos 120×40 px para que se vea nítida.");
        abort_if($size[0] > 3000 || $size[1] > 3000, 422, "La imagen es muy grande ({$size[0]}×{$size[1]} px): usa una de hasta 3000×3000 px.");

        return $validated['signature'];
    }

    public function uploadSignature(Request $request)
    {
        $tenant = $this->tenant($request, 'settings.edit');
        Documents::storeSignature($request, $tenant, self::validateSignature($request));

        return response()->json($this->payload($request, $tenant->fresh(['settings', 'businessType'])));
    }

    public function removeSignature(Request $request)
    {
        $tenant = $this->tenant($request, 'settings.edit');
        Documents::removeSignature($request, $tenant);

        return response()->json($this->payload($request, $tenant->fresh(['settings', 'businessType'])));
    }

    public function signatureFile(Request $request)
    {
        $tenant = $this->tenant($request);
        $path = Documents::signaturePath($tenant->id);
        abort_unless($path, 404);

        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'private, no-store']);
    }

    public function signupInfo(string $code)
    {
        abort_unless(preg_match('/^\d{' . Tenant::CODE_LENGTH . '}$/', $code), 404);
        $tenant = Tenant::with('settings')->where('code', $code)->where('status', 'active')->first();
        abort_if(!$tenant || $tenant->settings?->allow_customer_signup === false, 404, 'El código de empresa no es válido.');

        return response()->json([
            'name'    => $tenant->name,
            'logo'    => Branding::present($tenant)['logo_url'],
            'privacy' => PortalLook::privacyHtml($tenant),
        ]);
    }

    public function show(Request $request)
    {
        return response()->json($this->payload($request, $this->tenant($request)));
    }

    public function save(Request $request)
    {
        $tenant = $this->tenant($request, 'settings.edit');
        $hex = ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'];
        $message = 'Escribe el color como # y seis letras o números, por ejemplo #3F768C.';

        $validated = $request->validate([
            'primary'    => $hex,
            'accent'     => $hex,
            'menu'       => $hex,
            'short_name' => 'nullable|string|max:6',
        ], [
            'primary.regex'  => $message,
            'accent.regex'   => $message,
            'menu.regex'     => $message,
            'short_name.max' => 'La sigla puede tener hasta 6 letras.',
        ]);

        Branding::save($request, $tenant, $validated);

        return response()->json($this->payload($request, $tenant->fresh(['settings', 'businessType'])));
    }

    public function uploadLogo(Request $request)
    {
        $tenant = $this->tenant($request, 'settings.edit');
        $validated = $request->validate([
            'variant' => ['required', Rule::in(array_keys(Branding::VARIANTS))],
            'logo'    => 'required|file|mimes:png,jpg,jpeg,webp|max:1024',
        ], [
            'logo.required' => 'Elige la imagen de tu logo.',
            'logo.mimes'    => 'El logo debe ser una imagen PNG, JPG o WEBP.',
            'logo.max'      => 'El logo pesa más de 1 MB. Usa una imagen más liviana.',
        ]);

        $size = @getimagesize($validated['logo']->getRealPath());
        abort_unless($size, 422, 'Ese archivo no es una imagen válida.');
        abort_if($size[0] < 64 || $size[1] < 32, 422, "El logo es muy pequeño ({$size[0]}×{$size[1]} px): usa uno de al menos 64×32 px para que se vea nítido.");
        abort_if($size[0] > 4000 || $size[1] > 4000, 422, "El logo es muy grande ({$size[0]}×{$size[1]} px): usa uno de hasta 4000×4000 px.");

        Branding::storeLogo($request, $tenant, $validated['logo'], $validated['variant']);

        return response()->json($this->payload($request, $tenant->fresh(['settings', 'businessType'])));
    }

    public function removeLogo(Request $request, string $variant)
    {
        $tenant = $this->tenant($request, 'settings.edit');
        abort_unless(isset(Branding::VARIANTS[$variant]), 404);
        Branding::removeLogo($request, $tenant, $variant);

        return response()->json($this->payload($request, $tenant->fresh(['settings', 'businessType'])));
    }

    public function file(string $tenant, string $name)
    {
        abort_unless(Str::isUuid($tenant), 404);
        $variant = array_search($name, Branding::VARIANTS, true);
        abort_unless($variant !== false, 404);
        $path = Branding::logoPath($tenant, $variant);
        abort_unless($path, 404);

        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'public, max-age=86400']);
    }
}
