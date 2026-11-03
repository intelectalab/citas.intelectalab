<?php

namespace App\Support;

use App\Models\AuditEvent;
use App\Models\PlatformSetting;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class PlatformLook
{
    public const CACHE_KEY = 'platform.public';

    public const FILES = ['logo', 'logo-dark', 'landing', 'login'];

    public const ICONS = [
        'calendar', 'users', 'wallet', 'chart', 'shield', 'sparkles', 'clock', 'boxes',
        'heart', 'star', 'message', 'check', 'building', 'layers', 'file', 'award',
    ];

    public const BACKGROUNDS = ['brand', 'pattern-dots', 'pattern-grid', 'pattern-waves', 'image'];

    public const DEFAULTS = [
        'brand' => [
            'short_name'  => null,
            'primary'     => null,
            'accent'      => null,
            'menu'        => null,
            'made_by'     => null,
            'made_by_url' => null,
        ],
        'landing' => [
            'tag'              => 'Plataforma Empresarial SaaS',
            'title'            => 'Gestiona tu empresa desde una sola plataforma.',
            'highlight'        => null,
            'description'      => 'Selecciona el plan que necesitas, registra tu empresa y accede de forma inmediata a los módulos habilitados para tu suscripción.',
            'primary_button'   => 'Ver planes',
            'secondary_button' => 'Ya tengo una cuenta',
            'plans_title'      => 'Elige el plan para tu empresa',
            'plans_text'       => 'Los módulos disponibles dependen del plan contratado.',
            'cards'            => [],
        ],
        'login' => [
            'tag'         => 'ERP Multi-Tenant',
            'title'       => 'Gestiona tus operaciones en un solo lugar.',
            'highlight'   => null,
            'description' => 'Acceso centralizado para finanzas, inventario, clientes y servicios especializados.',
            'cards'       => [
                ['icon' => 'shield', 'title' => 'Todo tu negocio conectado', 'text' => 'Mantén el control de tus ventas, sucursales y citas en una sola plataforma.'],
            ],
            'background'  => 'brand',
            'panel_side'  => 'left',
        ],
        'maintenance' => [
            'message'   => null,
            'starts_at' => null,
            'ends_at'   => null,
        ],
    ];

    public const SECTIONS = ['brand', 'landing', 'login', 'maintenance'];

    private static function stored(string $section): array
    {
        $value = PlatformSetting::query()->where('key', "look.{$section}")->value('value');

        return is_array($value) ? $value : (is_string($value) ? (json_decode($value, true) ?: []) : []);
    }

    public static function section(string $section): array
    {
        try {
            $stored = self::stored($section);
        } catch (\Throwable) {
            $stored = [];
        }

        return array_merge(self::DEFAULTS[$section], array_intersect_key($stored, self::DEFAULTS[$section]));
    }

    public static function fileUrl(string $name): ?string
    {
        $path = self::filePath($name);

        return $path ? rtrim((string) config('app.url'), '/') . "/api/platform/files/{$name}?v=" . Storage::disk('local')->lastModified($path) : null;
    }

    public static function filePath(string $name): ?string
    {
        if (!in_array($name, self::FILES, true)) {
            return null;
        }

        foreach (Storage::disk('local')->files('platform') as $file) {
            if (pathinfo($file, PATHINFO_FILENAME) === $name) {
                return $file;
            }
        }

        return null;
    }

    public static function maintenance(): ?array
    {
        $m = self::section('maintenance');

        if (!trim((string) $m['message'])) {
            return null;
        }

        if ($m['ends_at'] && Carbon::parse($m['ends_at'])->isPast()) {
            return null;
        }

        return $m;
    }

    public static function present(bool $admin = false): array
    {
        $files = collect(self::FILES)->mapWithKeys(fn ($name) => [$name => self::fileUrl($name)])->all();

        return [
            'brand'       => self::section('brand') + ['logo_url' => $files['logo'], 'logo_dark_url' => $files['logo-dark']],
            'landing'     => self::section('landing') + ['image_url' => $files['landing']],
            'login'       => self::section('login') + ['image_url' => $files['login']],
            'maintenance' => $admin ? self::section('maintenance') : self::maintenance(),
        ];
    }

    public static function publicPayload(): array
    {
        $payload = Cache::rememberForever(self::CACHE_KEY, fn () => PlatformSetting::publicValues() + ['look' => self::present()]);
        $payload['look']['maintenance'] = self::maintenance();
        $payload['card_on_signup'] = PlanBilling::cardEnabled();

        return $payload;
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    public static function rules(string $section): array
    {
        $hex = ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'];
        $cards = [
            'cards'         => 'present|array|max:3',
            'cards.*.icon'  => ['required', Rule::in(self::ICONS)],
            'cards.*.title' => 'required|string|max:40',
            'cards.*.text'  => 'nullable|string|max:140',
        ];

        return match ($section) {
            'brand' => [
                'product_name' => 'required|string|max:60',
                'short_name'   => 'nullable|string|max:6',
                'primary'      => $hex,
                'accent'       => $hex,
                'menu'         => $hex,
                'accent_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
                'made_by'      => 'nullable|string|max:80',
                'made_by_url'  => 'nullable|url|max:190',
            ],
            'landing' => [
                'tag'              => 'nullable|string|max:60',
                'title'            => 'required|string|max:120',
                'highlight'        => 'nullable|string|max:80',
                'description'      => 'nullable|string|max:300',
                'primary_button'   => 'required|string|max:30',
                'secondary_button' => 'required|string|max:30',
                'plans_title'      => 'required|string|max:120',
                'plans_text'       => 'nullable|string|max:200',
            ] + $cards,
            'login' => [
                'tag'         => 'nullable|string|max:60',
                'title'       => 'required|string|max:120',
                'highlight'   => 'nullable|string|max:80',
                'description' => 'nullable|string|max:300',
                'background'  => ['required', Rule::in(self::BACKGROUNDS)],
                'panel_side'  => ['required', Rule::in(['left', 'right'])],
            ] + $cards,
            'maintenance' => [
                'message'   => 'nullable|string|max:300',
                'starts_at' => 'nullable|date',
                'ends_at'   => 'nullable|date|after:starts_at',
            ],
        };
    }

    public const MESSAGES = [
        'product_name.required'     => 'Escribe el nombre del producto.',
        'title.required'            => 'Escribe el título.',
        'primary_button.required'   => 'Escribe el texto del botón principal.',
        'secondary_button.required' => 'Escribe el texto del segundo botón.',
        'plans_title.required'      => 'Escribe el título de la sección de planes.',
        'cards.max'                 => 'Puedes poner hasta 3 tarjetas.',
        'cards.*.icon.required'     => 'Elige el ícono de cada tarjeta.',
        'cards.*.icon.in'           => 'Elige un ícono de la lista.',
        'cards.*.title.required'    => 'Cada tarjeta necesita un título.',
        'cards.*.title.max'         => 'El título de una tarjeta puede tener hasta 40 letras.',
        'cards.*.text.max'          => 'El texto de una tarjeta puede tener hasta 140 letras.',
        'made_by_url.url'           => 'Escribe el enlace completo, por ejemplo https://intelectalab.com.',
        'ends_at.after'             => 'El aviso debe terminar después de empezar.',
        'primary.regex'             => 'Escribe el color como # y seis letras o números, por ejemplo #3F768C.',
        'accent.regex'              => 'Escribe el color como # y seis letras o números, por ejemplo #3F768C.',
        'menu.regex'                => 'Escribe el color como # y seis letras o números, por ejemplo #3F768C.',
        'accent_color.regex'        => 'Escribe el color como # y seis letras o números, por ejemplo #10B981.',
    ];

    public static function save(Request $request, string $section, array $values): void
    {
        $before = self::section($section);
        $data = array_intersect_key($values, self::DEFAULTS[$section]);

        if ($section === 'brand') {
            $productBefore = PlatformSetting::values();
            PlatformSetting::put(['product_name' => trim($values['product_name']), 'accent_color' => strtoupper($values['accent_color'])]);
            $before += ['product_name' => $productBefore['product_name'], 'accent_color' => $productBefore['accent_color']];
            $data['short_name'] = isset($data['short_name']) && trim((string) $data['short_name']) !== '' ? mb_strtoupper(trim($data['short_name'])) : null;
            foreach (['primary', 'accent', 'menu'] as $key) {
                $data[$key] = isset($data[$key]) && $data[$key] ? strtoupper($data[$key]) : null;
            }
        }

        if (isset($data['cards'])) {
            $data['cards'] = array_values(array_map(fn ($card) => [
                'icon'  => $card['icon'],
                'title' => trim($card['title']),
                'text'  => trim((string) ($card['text'] ?? '')),
            ], $data['cards']));
        }

        PlatformSetting::updateOrCreate(['key' => "look.{$section}"], ['value' => array_merge(self::DEFAULTS[$section], $data)]);
        self::forget();

        $after = self::section($section) + ($section === 'brand' ? ['product_name' => trim($values['product_name']), 'accent_color' => strtoupper($values['accent_color'])] : []);
        $changed = array_keys(array_filter($after, fn ($value, $key) => ($before[$key] ?? null) !== $value, ARRAY_FILTER_USE_BOTH));

        if ($changed) {
            AuditEvent::record($request, "platform.look_{$section}_changed", null, array_intersect_key($before, array_flip($changed)), array_intersect_key($after, array_flip($changed)));
        }
    }

    public static function reset(Request $request, string $section): void
    {
        $before = self::section($section);
        PlatformSetting::query()->where('key', "look.{$section}")->delete();
        self::forget();
        AuditEvent::record($request, "platform.look_{$section}_reset", null, $before, self::DEFAULTS[$section]);
    }

    public static function storeFile(Request $request, string $name, UploadedFile $file): void
    {
        self::removeFile($name);
        $extension = strtolower($file->extension() ?: 'png');
        Storage::disk('local')->putFileAs('platform', $file, "{$name}.{$extension}");
        self::forget();
        AuditEvent::record($request, 'platform.look_file_changed', null, null, ['archivo' => $name]);
    }

    public static function deleteFile(Request $request, string $name): void
    {
        self::removeFile($name);
        self::forget();
        AuditEvent::record($request, 'platform.look_file_removed', null, ['archivo' => $name], null);
    }

    private static function removeFile(string $name): void
    {
        while ($path = self::filePath($name)) {
            Storage::disk('local')->delete($path);
        }
    }
}
