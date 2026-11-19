<?php

namespace App\Support;

use App\Models\Appointment;
use Illuminate\Support\Facades\Schema;

class VideoLinks
{
    private static ?bool $ready = null;

    public static function ready(): bool
    {
        return self::$ready ??= Schema::hasColumn('services', 'video_url') && Schema::hasColumn('appointments', 'video_url');
    }

    public static function for(Appointment $appointment): ?string
    {
        if (!self::ready()) {
            return null;
        }

        if ($own = trim((string) $appointment->video_url)) {
            return $own;
        }

        $service = $appointment->relationLoaded('service') ? $appointment->service : $appointment->service()->first();

        return $service && $service->is_virtual && trim((string) $service->video_url) ? trim((string) $service->video_url) : null;
    }

    public static function rule(): string
    {
        return 'nullable|string|max:500|url:http,https';
    }
}
