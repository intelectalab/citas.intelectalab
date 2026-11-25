<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class WebPosts
{
    public const NOT_READY = 'Falta actualizar la base de datos para usar el blog. Pide que se ejecute la actualización (php artisan migrate).';

    public const SEO_DESCRIPTION_MAX = 160;

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasTable('web_posts');
    }

    public static function slug(string $text): string
    {
        return mb_substr(trim(Str::slug($text), '-'), 0, 80);
    }

    public static function seoWarnings(object|array $post): array
    {
        $post = (object) $post;
        $warnings = [];
        $title = trim((string) ($post->seo_title ?? ''));
        $description = trim((string) ($post->seo_description ?? ''));

        if ($title === '') {
            $warnings[] = 'Falta el título para buscadores.';
        } elseif (mb_strlen($title) > 65) {
            $warnings[] = 'El título para buscadores pasa de 65 caracteres (' . mb_strlen($title) . '): Google lo cortará.';
        }

        if ($description === '') {
            $warnings[] = 'Falta la descripción para buscadores.';
        } elseif (mb_strlen($description) > self::SEO_DESCRIPTION_MAX) {
            $warnings[] = 'La descripción para buscadores pasa de ' . self::SEO_DESCRIPTION_MAX . ' caracteres (' . mb_strlen($description) . ').';
        }

        if (!($post->cover_path ?? null)) {
            $warnings[] = 'No tiene portada: al compartirlo saldrá sin imagen.';
        }

        $body = (string) ($post->body ?? '');
        $text = RichText::toText($body);
        $words = str_word_count(Str::ascii($text));

        if (trim((string) ($post->excerpt ?? '')) === '') {
            $warnings[] = 'Falta el resumen: es lo que se ve en la lista del blog.';
        }

        if ($words < 300) {
            $warnings[] = "El artículo es corto ({$words} palabras): a Google le van mejor los de 300 palabras o más.";
        }

        if (!preg_match('/<h[23][\s>]/i', $body)) {
            $warnings[] = 'No tiene subtítulos: divide el texto con al menos un subtítulo.';
        }

        if (preg_match_all('/<img\b(?![^>]*\balt="[^"]+")[^>]*>/i', $body) > 0) {
            $warnings[] = 'Hay imágenes sin texto alternativo: descríbelas para buscadores y lectores de pantalla.';
        }

        if (!preg_match('#href="/(register|precios|rubros|blog)#i', $body)) {
            $warnings[] = 'No enlaza a otras páginas del sitio (por ejemplo a Precios o a la prueba gratis).';
        }

        $keyword = mb_strtolower(trim((string) ($post->keyword ?? '')));

        if ($keyword === '') {
            $warnings[] = 'Falta la palabra clave: la frase que la gente escribiría en Google para encontrarlo.';
        } else {
            $has = fn (?string $value) => str_contains(Str::ascii(mb_strtolower((string) $value)), Str::ascii($keyword));
            $first = mb_substr($text, 0, 600);

            if (!$has($post->title ?? '') && !$has($title)) {
                $warnings[] = 'La palabra clave no está en el título.';
            }

            if ($description !== '' && !$has($description)) {
                $warnings[] = 'La palabra clave no está en la descripción para buscadores.';
            }

            if (!$has($first)) {
                $warnings[] = 'La palabra clave no aparece al comienzo del texto.';
            }

            if (!str_contains((string) ($post->slug ?? ''), Str::slug($keyword))) {
                $warnings[] = 'La dirección del artículo no lleva la palabra clave.';
            }
        }

        return $warnings;
    }

    public static function statsReady(): bool
    {
        static $ready = null;

        return $ready ??= self::ready() && Schema::hasColumn('web_posts', 'views') && Schema::hasColumn('web_posts', 'keyword');
    }

    public static function countView(string $slug): void
    {
        if (self::statsReady()) {
            DB::table('web_posts')->where('slug', $slug)->where('status', 'published')->increment('views');
        }
    }

    public static function stats(): array
    {
        if (!Schema::hasTable('web_leads')) {
            return [];
        }

        return DB::table('web_leads')->whereRaw("utm->>'post' IS NOT NULL")
            ->selectRaw("utm->>'post' AS slug, COUNT(*) AS leads, COUNT(*) FILTER (WHERE source = 'registro') AS signups")
            ->groupByRaw("utm->>'post'")->get()
            ->mapWithKeys(fn ($row) => [$row->slug => ['leads' => (int) $row->leads, 'signups' => (int) $row->signups]])->all();
    }

    public static function coverUrl(object $post): ?string
    {
        return $post->cover_path ? url("/api/web/posts/{$post->id}/cover") . '?v=' . substr(md5((string) $post->updated_at), 0, 8) : null;
    }

    public static function present(object $post, bool $withBody = false): array
    {
        $names = DB::table('business_types')->pluck('name', 'slug');
        $text = RichText::toText($post->body);

        return [
            'id'                 => $post->id,
            'slug'               => $post->slug,
            'title'              => $post->title,
            'excerpt'            => $post->excerpt,
            'business_type'      => $post->business_type,
            'business_type_name' => $post->business_type ? ($names[$post->business_type] ?? null) : null,
            'seo_title'          => $post->seo_title,
            'seo_description'    => $post->seo_description,
            'cover_url'          => self::coverUrl($post),
            'status'             => $post->status,
            'published_at'       => $post->published_at,
            'updated_at'         => $post->updated_at,
            'author'             => $post->author,
            'keyword'            => $post->keyword ?? null,
            'views'              => (int) ($post->views ?? 0),
            'reading_minutes'    => max(1, (int) ceil(str_word_count($text) / 200)),
            'body'               => $withBody ? RichText::cleanForSite((string) $post->body) : null,
        ];
    }

    public static function published(int $limit = 20, ?string $businessType = null): array
    {
        return DB::table('web_posts')->where('status', 'published')->where('published_at', '<=', now())
            ->when($businessType, fn ($q) => $q->where('business_type', $businessType))
            ->orderByDesc('published_at')->limit($limit)->get()
            ->map(fn ($post) => self::present($post))->values()->all();
    }
}
