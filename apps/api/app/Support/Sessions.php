<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;

class Sessions
{
    public const EXPIRED = 'Tu sesión venció por seguridad. Vuelve a ingresar para continuar.';

    public const CODE = 'SESSION_EXPIRED';

    public static function maxMinutes(): int
    {
        return (int) config('sanctum.expiration');
    }

    public static function staffMinutes(): int
    {
        return (int) config('sanctum.staff_expiration');
    }

    public static function idleMinutes(User|Customer $account): int
    {
        $tenant = $account->tenant_id ? $account->tenant : null;

        if ($account instanceof Customer) {
            return $tenant ? (int) AppointmentFlow::rule($tenant, 'session.portal_idle_minutes') : 0;
        }

        return $tenant ? Access::idleMinutes($tenant, $account) : (int) AppointmentFlow::RULE_DEFAULTS['session.idle_minutes'];
    }

    public static function check(mixed $token, bool $valid): bool
    {
        $account = $token?->tokenable;

        if (!$valid || !($account instanceof User || $account instanceof Customer)) {
            self::mark('expired');

            return false;
        }

        $staff = self::staffMinutes();

        if ($account instanceof User && $staff > 0 && $token->created_at->lte(now()->subMinutes($staff))) {
            self::mark('expired');

            return false;
        }

        $idle = Rls::bypass(fn () => self::idleMinutes($account));
        $last = $token->last_used_at ?? $token->created_at;

        if ($idle > 0 && $last->lte(now()->subMinutes($idle))) {
            self::mark('idle', $idle);

            return false;
        }

        return true;
    }

    private static function mark(string $reason, ?int $minutes = null): void
    {
        request()->attributes->set('session_end', ['reason' => $reason, 'minutes' => $minutes]);
    }

    public static function response(Request $request): ?JsonResponse
    {
        $end = $request->attributes->get('session_end');

        if (!$end) {
            return null;
        }

        $idle = $end['reason'] === 'idle';

        return response()->json([
            'message' => $idle ? self::idleMessage((int) $end['minutes']) : self::EXPIRED,
            'code'    => self::CODE,
            'reason'  => $end['reason'],
            'minutes' => $end['minutes'],
        ], 401);
    }

    public static function idleMessage(int $minutes): string
    {
        $span = match (true) {
            $minutes === 60    => 'pasó 1 hora',
            $minutes % 60 === 0 => 'pasaron ' . ($minutes / 60) . ' horas',
            default            => "pasaron {$minutes} minutos",
        };

        return "Tu sesión se cerró porque {$span} sin actividad. Vuelve a ingresar para continuar.";
    }

    public static function prune(): int
    {
        $deleted = PersonalAccessToken::whereNotNull('expires_at')->where('expires_at', '<', now())->delete();

        if (self::maxMinutes() > 0) {
            $deleted += PersonalAccessToken::where('created_at', '<', now()->subMinutes(self::maxMinutes()))->delete();
        }

        if (self::staffMinutes() > 0) {
            $deleted += PersonalAccessToken::where('tokenable_type', User::class)->where('created_at', '<', now()->subMinutes(self::staffMinutes()))->delete();
        }

        return $deleted;
    }
}
