<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

class Channels
{
    public const CHANNELS = [
        'instagram' => 'Instagram',
        'facebook'  => 'Facebook',
        'tiktok'    => 'TikTok',
        'google'    => 'Google',
        'whatsapp'  => 'WhatsApp',
        'link'      => 'Enlace para redes',
        'qr'        => 'Código QR',
        'web'       => 'Tu página web',
        'direct'    => 'Directo (sin origen)',
        'portal'    => 'Portal de clientes',
    ];

    public const SOURCES = [
        'staff'        => 'Agendada por el equipo',
        'online'       => 'En línea (antes de medir el canal)',
        'portal'       => 'Portal de clientes',
        'walkin'       => 'Sin cita',
        'payment_link' => 'Link de pago',
        'package'      => 'Paquete',
    ];

    public const SHAREABLE = ['instagram', 'facebook', 'tiktok', 'google', 'whatsapp', 'qr', 'web'];

    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasColumn('appointments', 'channel');
    }

    public static function normalize(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        return array_key_exists($value, self::CHANNELS) ? $value : 'direct';
    }

    public static function remember(mixed $value): void
    {
        request()->attributes->set('booking_channel', self::normalize($value));
    }

    public static function current(): ?string
    {
        $value = request()->attributes->get('booking_channel');

        return is_string($value) && array_key_exists($value, self::CHANNELS) ? $value : null;
    }

    public static function column(): array
    {
        $channel = self::current();

        return $channel && self::ready() ? ['channel' => $channel] : [];
    }

    public static function linkColumn(): array
    {
        $channel = self::current();

        return $channel && Schema::hasColumn('payment_links', 'channel') ? ['channel' => $channel] : [];
    }

    public static function label(?string $channel, ?string $source = null): string
    {
        if ($channel && isset(self::CHANNELS[$channel])) {
            return self::CHANNELS[$channel];
        }

        return self::SOURCES[$source] ?? ($source ? ucfirst($source) : 'Sin dato');
    }

    public static function links(string $baseUrl): array
    {
        return collect(self::SHAREABLE)->map(fn ($key) => [
            'key'   => $key,
            'label' => self::CHANNELS[$key],
            'url'   => $baseUrl . '?origen=' . $key,
        ])->values()->all();
    }
}
