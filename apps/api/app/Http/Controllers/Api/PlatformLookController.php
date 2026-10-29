<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Support\PlatformLook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PlatformLookController extends Controller
{
    private function payload(): array
    {
        $values = PlatformSetting::values();

        return [
            'look'     => PlatformLook::present(true),
            'product'  => ['product_name' => $values['product_name'], 'accent_color' => $values['accent_color']],
            'defaults' => PlatformLook::DEFAULTS + ['product' => array_intersect_key(PlatformSetting::DEFAULTS, array_flip(['product_name', 'accent_color']))],
            'icons'    => PlatformLook::ICONS,
        ];
    }

    public function show()
    {
        return response()->json($this->payload());
    }

    public function save(Request $request, string $section)
    {
        abort_unless(in_array($section, PlatformLook::SECTIONS, true), 404);
        $validated = $request->validate(PlatformLook::rules($section), PlatformLook::MESSAGES);
        PlatformLook::save($request, $section, $validated);

        return response()->json($this->payload());
    }

    public function reset(Request $request, string $section)
    {
        abort_unless(in_array($section, PlatformLook::SECTIONS, true), 404);
        PlatformLook::reset($request, $section);

        if ($section === 'brand') {
            PlatformSetting::put(array_intersect_key(PlatformSetting::DEFAULTS, array_flip(['product_name', 'accent_color'])));
            PlatformLook::forget();
        }

        $files = ['brand' => ['logo', 'logo-dark'], 'landing' => ['landing'], 'login' => ['login']][$section] ?? [];

        foreach ($files as $name) {
            if (PlatformLook::filePath($name)) {
                PlatformLook::deleteFile($request, $name);
            }
        }

        return response()->json($this->payload());
    }

    public function upload(Request $request, string $name)
    {
        abort_unless(in_array($name, PlatformLook::FILES, true), 404);
        $isLogo = str_starts_with($name, 'logo');
        $max = $isLogo ? 1024 : 3072;

        $file = $request->validate(['file' => "required|file|mimes:png,jpg,jpeg,webp|max:{$max}"], [
            'file.required' => 'Elige la imagen.',
            'file.mimes'    => 'La imagen debe ser PNG, JPG o WEBP.',
            'file.max'      => $isLogo ? 'El logo pesa más de 1 MB. Usa una imagen más liviana.' : 'La imagen pesa más de 3 MB. Usa una más liviana.',
        ])['file'];

        $size = @getimagesize($file->getRealPath());
        abort_unless($size, 422, 'Ese archivo no es una imagen válida.');
        [$minW, $minH] = $isLogo ? [64, 32] : [600, 400];
        abort_if($size[0] < $minW || $size[1] < $minH, 422, "La imagen es muy pequeña ({$size[0]}×{$size[1]} px): usa una de al menos {$minW}×{$minH} px para que se vea nítida.");
        abort_if($size[0] > 5000 || $size[1] > 5000, 422, "La imagen es muy grande ({$size[0]}×{$size[1]} px): usa una de hasta 5000×5000 px.");

        PlatformLook::storeFile($request, $name, $file);

        return response()->json($this->payload());
    }

    public function remove(Request $request, string $name)
    {
        abort_unless(in_array($name, PlatformLook::FILES, true), 404);
        PlatformLook::deleteFile($request, $name);

        return response()->json($this->payload());
    }

    public function file(string $name)
    {
        $path = PlatformLook::filePath($name);
        abort_unless($path, 404);

        return response()->file(Storage::disk('local')->path($path), ['Cache-Control' => 'public, max-age=86400']);
    }
}
