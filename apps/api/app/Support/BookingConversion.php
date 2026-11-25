<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BookingConversion
{
    public static function ready(): bool
    {
        return Schema::hasColumn('payment_links', 'tracked_at');
    }

    public static function applies(object $link): bool
    {
        return $link->purpose === 'deposit' && !empty($link->channel ?? null);
    }

    public static function view(Tenant $tenant, object $link, ?array $booking): ?array
    {
        if (!self::applies($link)) {
            return null;
        }

        $tracking = LinkPage::tracking($tenant);

        if (!$tracking['ga4_id'] && !$tracking['meta_pixel_id']) {
            return null;
        }

        return [
            'tracking'       => $tracking,
            'transaction_id' => $link->appointment_id ? (DB::table('appointments')->where('id', $link->appointment_id)->value('code') ?? $link->id) : $link->id,
            'value'          => $booking['price'] ?? round((float) $link->amount, 2),
            'deposit'        => round((float) $link->amount, 2),
            'currency'       => $link->currency,
            'service'        => $booking['service'] ?? null,
            'sent'           => self::ready() && !empty($link->tracked_at ?? null),
        ];
    }

    public static function mark(string $token): array
    {
        $link = DB::table('payment_links')->where('token', $token)->first();
        abort_unless($link !== null, 404, 'Este link de pago no existe.');

        if ($link->status !== 'paid' || !self::applies($link)) {
            return ['send' => false];
        }

        if (!self::ready()) {
            return ['send' => true];
        }

        $updated = DB::table('payment_links')->where('id', $link->id)->whereNull('tracked_at')->update(['tracked_at' => now()]);

        return ['send' => $updated === 1];
    }
}
