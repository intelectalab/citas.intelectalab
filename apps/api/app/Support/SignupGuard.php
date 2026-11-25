<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SignupGuard
{
    public const TRAP_FIELD = 'website';

    public static function token(?Carbon $at = null): string
    {
        return Crypt::encryptString(json_encode(['t' => ($at ?? now())->getTimestamp(), 'n' => bin2hex(random_bytes(6))]));
    }

    public static function siteKey(): ?string
    {
        return Captcha::siteKey('signup');
    }

    public static function turnstileOn(): bool
    {
        return Captcha::on('signup');
    }

    public static function check(Request $request): void
    {
        if (filled($request->input(self::TRAP_FIELD))) {
            self::fail('No pudimos completar el registro. Recarga la página y vuelve a intentarlo.', 'SIGNUP_BLOCKED');
        }

        $issued = self::issuedAt((string) $request->input('form_token', ''));

        if ($issued === null) {
            self::fail('El formulario no es válido o ya venció. Recarga la página y vuelve a llenarlo.', 'SIGNUP_FORM_EXPIRED');
        }

        $elapsed = now()->getTimestamp() - $issued;

        if ($elapsed > max(1, (int) config('services.signup.max_hours', 12)) * 3600) {
            self::fail('El formulario no es válido o ya venció. Recarga la página y vuelve a llenarlo.', 'SIGNUP_FORM_EXPIRED');
        }

        if ($elapsed < (int) config('services.signup.min_seconds', 4)) {
            self::fail('Llenaste el formulario demasiado rápido. Revisa tus datos y vuelve a intentarlo en unos segundos.', 'SIGNUP_TOO_FAST');
        }

        Captcha::assert($request, 'signup');
    }

    public static function ownerEmailTaken(string $email): bool
    {
        return User::where('is_owner', true)
            ->whereNotNull('tenant_id')
            ->whereRaw('lower(email) = ?', [mb_strtolower(trim($email))])
            ->exists();
    }

    public static function lockEmail(string $email): void
    {
        DB::select('select pg_advisory_xact_lock(hashtext(?))', ['signup|' . mb_strtolower(trim($email))]);

        if (self::ownerEmailTaken($email)) {
            throw ValidationException::withMessages(['email' => self::takenMessage()]);
        }
    }

    public static function takenMessage(): string
    {
        return 'Ya hay una empresa registrada con este correo. Inicia sesión o usa otro correo.';
    }

    private static function issuedAt(string $token): ?int
    {
        if ($token === '') {
            return null;
        }

        try {
            $data = json_decode(Crypt::decryptString($token), true);
        } catch (\Throwable) {
            return null;
        }

        return is_array($data) && is_int($data['t'] ?? null) ? $data['t'] : null;
    }

    private static function fail(string $message, string $code): never
    {
        throw new \Illuminate\Http\Exceptions\HttpResponseException(
            response()->json(['message' => $message, 'code' => $code], 422)
        );
    }
}
