<?php

namespace App\Support;

class RichText
{
    private const KEEP = ['p', 'h2', 'h3', 'ul', 'ol', 'li', 'strong', 'em', 'u'];

    private const ALIASES = ['b' => 'strong', 'i' => 'em', 'h1' => 'h2', 'h4' => 'h3', 'h5' => 'h3', 'h6' => 'h3', 'div' => 'p'];

    private const DROP = ['script', 'style', 'iframe', 'object', 'embed', 'head', 'title', 'meta', 'link', 'svg', 'math', 'form', 'input', 'textarea', 'select', 'button', 'img', 'video', 'audio', 'template', 'noscript'];

    private const URL = '#\bhttps?://[^\s<>"\']+[^\s<>"\'.,;:!?)\]]#u';

    public static function isHtml(?string $text): bool
    {
        return (bool) preg_match('#<(p|br|strong|b|em|i|u|h[1-6]|ul|ol|li|a|div)\b[^>]*>#i', (string) $text);
    }

    public static function fromText(string $text): string
    {
        $text = trim(str_replace(["\r\n", "\r"], "\n", $text));

        if ($text === '') {
            return '';
        }

        return collect(preg_split("/\n{2,}/", $text))->map(fn ($part) => '<p>' . nl2br(e($part), false) . '</p>')->implode('');
    }

    public static function toHtml(?string $body): string
    {
        return self::isHtml($body) ? self::clean((string) $body) : self::fromText((string) $body);
    }

    public static function clean(string $html): string
    {
        return self::walk(self::root($html), null);
    }

    public static function normalize(?string $body): string
    {
        $body = trim((string) $body);

        return $body === '' ? '' : (self::isHtml($body) ? self::clean($body) : $body);
    }

    public static function same(?string $a, ?string $b): bool
    {
        return preg_replace('#<p></p>#', '', self::toHtml($a)) === preg_replace('#<p></p>#', '', self::toHtml($b));
    }

    public static function fill(string $template, array $vars): string
    {
        $tokens = collect($vars)->mapWithKeys(fn ($value, $key) => ["[{$key}]" => (string) $value]);

        if (!self::isHtml($template)) {
            return strtr($template, $tokens->all());
        }

        $template = preg_replace_callback('#href="([^"]*)"#', fn ($m) => 'href="' . strtr($m[1], $tokens->map(fn ($v) => e($v))->all()) . '"', $template);

        return strtr($template, $tokens->map(fn ($v) => nl2br(e($v), false))->all());
    }

    public static function append(string $body, string $text): string
    {
        return self::isHtml($body) ? $body . self::fromText($text) : rtrim($body) . "\n\n" . $text;
    }

    public static function toText(?string $body): string
    {
        $body = (string) $body;

        if (!self::isHtml($body)) {
            return $body;
        }

        $text = preg_replace_callback('#<a href="([^"]*)">(.*?)</a>#s', fn ($m) => strip_tags($m[2]) === html_entity_decode($m[1]) ? $m[2] : "{$m[2]} ({$m[1]})", self::clean($body));
        $text = preg_replace(['#<li><p>(.*?)</p></li>#s', '#<br>\s*#', '#<li>#', '#</(p|h2|h3)>#', '#</li>#', '#</(ul|ol)>#'], ['<li>$1</li>', "\n", '• ', "\n\n", "\n", "\n"], $text);
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace("/\n{3,}/", "\n\n", $text));
    }

    public static function email(string $body, string $color): string
    {
        $styles = [
            'p'  => 'margin:0 0 14px',
            'h2' => 'margin:0 0 12px;font-size:22px;line-height:1.3;color:#0f172a',
            'h3' => 'margin:0 0 10px;font-size:17px;line-height:1.35;color:#0f172a',
            'ul' => 'margin:0 0 14px;padding-left:22px',
            'ol' => 'margin:0 0 14px;padding-left:22px',
            'li' => 'margin:0 0 4px',
            'a'  => "color:{$color};text-decoration:underline",
        ];

        return self::walk(self::root(self::toHtml($body)), $styles);
    }

    private static function root(string $html): \DOMElement
    {
        $dom = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8"?><div id="rt-root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD | LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return $dom->getElementById('rt-root') ?? $dom->createElement('div');
    }

    private static function href(string $href): ?string
    {
        $href = trim($href);

        return preg_match('#^(https?://|mailto:)\S+$#i', $href) || preg_match('#^\[[^\]\s]+\]$#u', $href) ? $href : null;
    }

    private static function walk(\DOMNode $node, ?array $styles, bool $inLink = false, bool $inItem = false): string
    {
        $out = '';

        foreach ($node->childNodes as $child) {
            if ($child instanceof \DOMText) {
                $text = e($child->nodeValue);
                $out .= $styles !== null && !$inLink
                    ? preg_replace_callback(self::URL, fn ($m) => '<a href="' . $m[0] . '" target="_blank" rel="noopener" style="' . $styles['a'] . '">' . $m[0] . '</a>', $text)
                    : $text;

                continue;
            }

            if (!$child instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            $tag = self::ALIASES[$tag] ?? $tag;

            if (in_array($tag, self::DROP, true)) {
                continue;
            }

            if ($tag === 'br') {
                $out .= '<br>';

                continue;
            }

            $inner = self::walk($child, $styles, $inLink || $tag === 'a', $tag === 'li' || ($inItem && $tag !== 'ul' && $tag !== 'ol'));

            if ($tag === 'a') {
                $href = self::href($child->getAttribute('href'));
                $out .= $href && !$inLink
                    ? '<a href="' . e($href) . '"' . ($styles !== null ? ' target="_blank" rel="noopener" style="' . $styles['a'] . '"' : '') . '>' . $inner . '</a>'
                    : $inner;

                continue;
            }

            if (!in_array($tag, self::KEEP, true)) {
                $out .= $inner;

                continue;
            }

            if ($styles === null || !isset($styles[$tag])) {
                $out .= "<{$tag}>{$inner}</{$tag}>";

                continue;
            }

            $style = $tag === 'p' && $inItem ? 'margin:0' : $styles[$tag];
            $out .= "<{$tag} style=\"{$style}\">" . ($tag === 'p' && trim(strip_tags($inner)) === '' && !str_contains($inner, '<br>') ? '&nbsp;' : $inner) . "</{$tag}>";
        }

        return $out;
    }
}
