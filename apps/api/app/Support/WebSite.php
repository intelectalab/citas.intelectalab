<?php

namespace App\Support;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class WebSite
{
    public const LEGAL = [
        'terminos'   => 'Términos y condiciones',
        'privacidad' => 'Política de privacidad',
        'cookies'    => 'Política de cookies',
    ];

    public const FAQ = [
        ['q' => '¿Necesito tarjeta para la prueba gratis?', 'a' => 'No. Creas tu cuenta, usas todo el plan durante la prueba y recién al final decides si te quedas.'],
        ['q' => '¿Puedo cambiar de plan después?', 'a' => 'Sí. Puedes subir o bajar de plan cuando quieras desde Mi empresa; el cambio se aplica en tu siguiente periodo.'],
        ['q' => '¿Cómo pago?', 'a' => 'Con tarjeta de crédito o débito y cobro automático, o con transferencia, Yape o Plin si eliges el pago anual.'],
        ['q' => '¿Mis datos están seguros?', 'a' => 'Cada empresa ve solo lo suyo, todo viaja cifrado y puedes descargar o pedir la baja de tus datos cuando quieras.'],
        ['q' => '¿Mis clientes pueden reservar solos?', 'a' => 'Sí. Tienes una página de reservas propia con tus servicios y horarios, que puedes compartir por WhatsApp o redes.'],
    ];

    public static function defaults(): array
    {
        return [
            'url'              => null,
            'phone'            => null,
            'whatsapp'         => null,
            'whatsapp_message' => 'Hola, quiero saber más de {producto}.',
            'email'            => null,
            'seo_title'        => null,
            'seo_description'  => null,
            'rubros_title'     => 'Hecho para tu rubro',
            'rubros_text'      => 'Cada tipo de negocio empieza con sus servicios, sus palabras y sus reglas listas.',
            'pricing_title'    => 'Precios claros, sin sorpresas',
            'pricing_text'     => 'Empieza gratis y elige el plan cuando lo necesites. Paga por mes o ahorra pagando el año.',
            'cta_title'        => 'Prueba gratis, sin tarjeta',
            'cta_text'         => 'Crea tu cuenta en minutos y empieza a agendar hoy.',
            'contact_form'     => true,
            'faq'              => self::FAQ,
            'ga4_id'           => null,
            'meta_pixel_id'    => null,
            'cookie_banner'    => true,
            'cookie_text'      => 'Usamos cookies propias para que el sitio funcione y, si aceptas, cookies de medición para saber qué páginas sirven más.',
            'legal'            => [
                'terminos'   => self::defaultTerms(),
                'privacidad' => self::defaultPrivacy(),
                'cookies'    => self::defaultCookies(),
            ],
            'legal_updated_at' => null,
        ];
    }

    public static function settings(): array
    {
        $stored = PlatformSetting::value('site');
        $stored = is_array($stored) ? $stored : [];
        $defaults = self::defaults();
        $merged = array_merge($defaults, array_intersect_key($stored, $defaults));
        $merged['legal'] = array_merge($defaults['legal'], array_intersect_key((array) ($stored['legal'] ?? []), $defaults['legal']));
        $merged['faq'] = array_values(array_filter((array) $merged['faq'], fn ($item) => is_array($item) && trim((string) ($item['q'] ?? '')) !== '' && trim((string) ($item['a'] ?? '')) !== ''));

        return $merged;
    }

    public static function fill(string $text): string
    {
        $values = PlatformSetting::values();
        $site = PlatformSetting::value('site');

        return strtr($text, [
            '{producto}'  => (string) ($values['product_name'] ?? ''),
            '{empresa}'   => (string) ($values['legal_name'] ?: $values['product_name']),
            '{ruc}'       => (string) ($values['legal_tax_id'] ?: '—'),
            '{direccion}' => (string) ($values['legal_address'] ?: '—'),
            '{correo}'    => (string) ((is_array($site) ? ($site['email'] ?? null) : null) ?: ($values['support_email'] ?: '—')),
        ]);
    }

    public static function publicPayload(): array
    {
        $site = self::settings();
        $values = PlatformSetting::values();
        $look = PlatformLook::publicPayload()['look'] ?? [];

        return [
            'product_name'     => $values['product_name'],
            'allow_signup'     => (bool) $values['allow_tenant_signup'],
            'url'              => $site['url'],
            'phone'            => $site['phone'],
            'whatsapp'         => $site['whatsapp'] ? preg_replace('/\D/', '', $site['whatsapp']) : null,
            'whatsapp_message' => self::fill((string) $site['whatsapp_message']),
            'email'            => $site['email'] ?: $values['support_email'],
            'seo_title'        => $site['seo_title'],
            'seo_description'  => $site['seo_description'],
            'texts'            => array_intersect_key($site, array_flip(['rubros_title', 'rubros_text', 'pricing_title', 'pricing_text', 'cta_title', 'cta_text'])),
            'contact_form'     => (bool) $site['contact_form'],
            'faq'              => $site['faq'],
            'analytics'        => ['ga4_id' => $site['ga4_id'], 'meta_pixel_id' => $site['meta_pixel_id']],
            'cookie_banner'    => (bool) $site['cookie_banner'],
            'cookie_text'      => $site['cookie_text'],
            'legal_name'       => $values['legal_name'],
            'legal_tax_id'     => $values['legal_tax_id'],
            'landing'          => $look['landing'] ?? null,
            'brand'            => $look['brand'] ?? null,
        ];
    }

    public static function plans(): array
    {
        $hasPopular = Schema::hasColumn('saas_plans', 'is_popular');

        return DB::table('saas_plans')->where('status', 'active')->where('is_public_catalog', true)->orderBy('presentation_order')->get()
            ->map(function ($plan) use ($hasPopular) {
                $json = fn ($value) => is_string($value) ? (json_decode($value, true) ?: []) : (array) $value;

                return [
                    'id'              => $plan->id,
                    'name'            => $plan->name,
                    'slug'            => $plan->slug,
                    'description'     => $plan->description,
                    'tagline'         => $hasPopular ? $plan->tagline : null,
                    'is_popular'      => $hasPopular && (bool) $plan->is_popular,
                    'price_monthly'   => round((float) $plan->price_monthly, 2),
                    'price_yearly'    => round((float) $plan->price_yearly, 2),
                    'currency'        => $plan->currency ?? 'PEN',
                    'currency_symbol' => Currency::present($plan->currency ?? 'PEN')['symbol'],
                    'trial_config'    => $json($plan->trial_config),
                    'modules'         => $json($plan->modules),
                    'limits'          => $json($plan->limits),
                    'updated_at'      => $plan->updated_at,
                ];
            })->values()->all();
    }

    public static function businessTypes(bool $withTemplate = false): array
    {
        return DB::table('business_types')->where('status', 'active')->where('slug', '!=', 'generic')->orderBy('sort_order')->get()
            ->map(function ($type) use ($withTemplate) {
                $vocabulary = is_string($type->vocabulary) ? (json_decode($type->vocabulary, true) ?: []) : (array) $type->vocabulary;
                $row = [
                    'slug'        => $type->slug,
                    'name'        => $type->name,
                    'short_name'  => $type->short_name,
                    'description' => $type->description,
                    'icon'        => $type->icon,
                    'color'       => $type->color,
                    'vocabulary'  => $vocabulary,
                    'updated_at'  => $type->updated_at,
                ];

                if ($withTemplate) {
                    $template = is_string($type->template) ? (json_decode($type->template, true) ?: []) : (array) $type->template;
                    $off = is_string($type->modules_off) ? (json_decode($type->modules_off, true) ?: []) : (array) $type->modules_off;
                    $row['categories'] = collect($template['categories'] ?? [])->map(fn ($category) => [
                        'name'     => $category['name'] ?? '',
                        'services' => collect($category['services'] ?? [])->take(6)->map(fn ($service) => [
                            'name'     => $service['name'] ?? '',
                            'minutes'  => (int) ($service['duration_minutes'] ?? 0),
                            'price'    => (float) ($service['price'] ?? 0),
                            'virtual'  => (bool) ($service['is_virtual'] ?? false),
                            'kind'     => $service['kind'] ?? 'individual',
                        ])->values(),
                    ])->take(4)->values();
                    $row['modules_off'] = array_values($off);
                    $row['dependents'] = is_string($type->dependents) ? (json_decode($type->dependents, true) ?: []) : (array) $type->dependents;
                }

                return $row;
            })->values()->all();
    }

    private static function defaultTerms(): string
    {
        return '<h2>1. Quiénes somos</h2><p>{producto} es un servicio de {empresa} (RUC {ruc}), con domicilio en {direccion}. Para cualquier consulta escríbenos a {correo}.</p>'
            . '<h2>2. El servicio</h2><p>Ofrecemos un sistema en línea para agendar citas, atender clientes, vender y llevar la caja de tu negocio. El alcance de cada plan se muestra en la página de precios.</p>'
            . '<h2>3. Tu cuenta</h2><p>Eres responsable de los datos que registras y de quienes usan tu cuenta. Cuida tu contraseña y avísanos si notas un ingreso que no reconoces.</p>'
            . '<h2>4. Prueba gratis y pagos</h2><p>La prueba gratis no pide tarjeta. Al terminar, eliges un plan y lo pagas por mes o por año. Si un pago no se completa, tienes unos días de gracia; después la cuenta queda en solo lectura hasta que se pague. Tus datos se conservan.</p>'
            . '<h2>5. Cupones y promociones</h2><p>Un cupón vale por el descuento, los meses y la vigencia que indica, y no se cambia por dinero.</p>'
            . '<h2>6. Baja del servicio</h2><p>Puedes pedir la baja cuando quieras desde Mi empresa. Antes puedes descargar todos tus datos.</p>'
            . '<h2>7. Reclamos</h2><p>Tienes a tu disposición nuestro Libro de Reclamaciones virtual. Respondemos dentro del plazo que fija la ley.</p>';
    }

    private static function defaultPrivacy(): string
    {
        return '<h2>Responsable</h2><p>{empresa} (RUC {ruc}), {direccion}, es responsable de los datos personales que se recogen en este sitio, conforme a la Ley N.° 29733 de Protección de Datos Personales y su reglamento.</p>'
            . '<h2>Qué datos usamos y para qué</h2><p>Los datos que nos das al registrarte o al pedir que te llamemos (nombre, correo, teléfono y datos de tu negocio) los usamos para crear tu cuenta, atenderte, cobrar tu plan y, si lo aceptas, enviarte novedades.</p>'
            . '<h2>Datos de tus clientes</h2><p>Lo que registras de tus propios clientes es tuyo: lo tratamos solo para darte el servicio, por encargo tuyo, y no lo usamos para nada más.</p>'
            . '<h2>Con quién se comparten</h2><p>Solo con los proveedores que necesitamos para dar el servicio (alojamiento, correo y pasarelas de pago), con las mismas reglas de cuidado.</p>'
            . '<h2>Tus derechos</h2><p>Puedes pedir acceso, rectificación, cancelación u oposición escribiendo a {correo}.</p>';
    }

    private static function defaultCookies(): string
    {
        return '<h2>Qué son</h2><p>Las cookies son pequeños archivos que el navegador guarda para recordar información de tu visita.</p>'
            . '<h2>Las que usamos siempre</h2><p>Las necesarias para que el sitio funcione y para recordar tu elección sobre las cookies. Para contar visitas usamos un conteo anónimo, sin datos personales.</p>'
            . '<h2>Las que usamos solo si aceptas</h2><p>Google Analytics y el píxel de Meta, para medir qué páginas y anuncios funcionan mejor. No se cargan hasta que pulses «Aceptar».</p>'
            . '<h2>Cómo cambiar tu elección</h2><p>Desde el enlace «Cookies» al pie de cada página puedes volver a elegir cuando quieras.</p>';
    }
}
