<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\User;
use App\Support\AccountSecurity;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class LoginController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'      => ['required', 'email'],
            'password'   => ['required', 'string'],
            'account_id' => ['nullable', 'uuid'],
            'device_token' => ['nullable', 'string', 'max:100'],
        ]);

        $email = trim($credentials['email']);

        $staff = User::with('tenant.businessType')->where('email', $email)->get();

        $customers = Customer::with('tenant.businessType')
            ->whereRaw('lower(email) = ?', [mb_strtolower($email)])
            ->whereNotNull('password_hash')
            ->get();

        $matches = $staff->concat($customers)
            ->filter(fn (User|Customer $account) => Hash::check($credentials['password'], $account->getAuthPassword()))
            ->values();

        if (!empty($credentials['account_id'])) {
            $matches = $matches->where('id', $credentials['account_id'])->values();
        }

        if ($matches->isEmpty()) {
            $attempts = $staff->concat($customers);

            foreach ($attempts as $attempted) {
                Audit::log($request, 'auth.failed', [
                    'actor'       => $attempted,
                    'tenant'      => $attempted->tenant_id,
                    'impersonator' => null,
                    'entity_type' => $attempted instanceof Customer ? 'customer' : 'user',
                    'entity_id'   => $attempted->id,
                ]);
                AccountSecurity::failed($request, $attempted);
            }

            $locked = $attempts->map(fn (User|Customer $attempted) => AccountSecurity::lockedMinutes($attempted))->filter();

            if ($attempts->isNotEmpty() && $locked->count() === $attempts->count()) {
                return response()->json(['message' => self::lockedMessage($locked->min()), 'code' => 'ACCOUNT_LOCKED'], 423);
            }

            return response()->json([
                'message' => 'Credenciales incorrectas'
            ], 401);
        }

        $enabled = fn (User|Customer $account) => $account instanceof Customer
            ? $account->portal_enabled !== false
            : $account->status === 'active';

        $allowed = $matches->filter(fn (User|Customer $account) => $enabled($account)
            && (is_null($account->tenant_id) || $account->tenant?->isActive()))->values();

        if ($allowed->isEmpty()) {
            foreach ($matches as $blocked) {
                Audit::log($request, 'auth.blocked', [
                    'actor'        => $blocked,
                    'tenant'       => $blocked->tenant_id,
                    'impersonator' => null,
                    'entity_type'  => $blocked instanceof Customer ? 'customer' : 'user',
                    'entity_id'    => $blocked->id,
                ]);
            }

            $accountDisabled = $matches->contains(fn (User|Customer $account) => !$enabled($account));
            $isCustomer = $matches->every(fn (User|Customer $account) => $account instanceof Customer);

            return response()->json([
                'message' => match (true) {
                    $accountDisabled && $isCustomer => 'Tu acceso al portal está desactivado. Comunícate con la empresa.',
                    $accountDisabled                => 'Tu cuenta está desactivada. Comunícate con el administrador de tu empresa.',
                    $isCustomer                     => 'El portal de esta empresa no está disponible por ahora.',
                    default                         => 'El acceso de tu empresa está suspendido. Comunícate con soporte.',
                },
                'code' => $accountDisabled ? 'ACCOUNT_DISABLED' : 'TENANT_SUSPENDED',
            ], 403);
        }

        $unlocked = $allowed->reject(fn (User|Customer $account) => AccountSecurity::lockedMinutes($account))->values();

        if ($unlocked->isEmpty()) {
            return response()->json(['message' => self::lockedMessage($allowed->map(fn (User|Customer $account) => AccountSecurity::lockedMinutes($account))->min()), 'code' => 'ACCOUNT_LOCKED'], 423);
        }

        $matches = $unlocked;

        if ($matches->count() > 1) {
            return response()->json([
                'requires_tenant_selection' => true,
                'accounts' => $matches->map(fn (User|Customer $account) => [
                    'account_id'    => $account->id,
                    'account_type'  => $account instanceof Customer ? 'customer' : 'staff',
                    'tenant_id'     => $account->tenant_id,
                    'tenant_name'   => $account->tenant?->name ?? 'Administración de la plataforma',
                    'business_type' => $account->tenant?->businessType?->short_name,
                    'color'         => $account->tenant?->businessType?->color,
                ]),
            ]);
        }

        $account = $matches->first();
        AccountSecurity::succeeded($account);

        if (AccountSecurity::codeRequired($account) && !AccountSecurity::trusted($account, $credentials['device_token'] ?? null)) {
            try {
                return response()->json(AccountSecurity::challenge($request, $account));
            } catch (\Throwable $e) {
                report($e);
                \App\Support\PlatformMail::markFailed($e, 'un código de ingreso');
                Audit::log($request, 'auth.code_skipped', [
                    'actor'        => $account,
                    'tenant'       => $account->tenant_id,
                    'impersonator' => null,
                    'entity_type'  => $account instanceof Customer ? 'customer' : 'user',
                    'entity_id'    => $account->id,
                ]);
            }
        }

        return response()->json(self::issue($request, $account));
    }

    public static function lockedMessage(?int $minutes): string
    {
        $minutes = max(1, (int) $minutes);

        return "Por seguridad bloqueamos tu cuenta después de varios intentos fallidos. Vuelve a intentarlo en {$minutes} " . ($minutes === 1 ? 'minuto' : 'minutos') . ', o usa "¿Olvidaste tu contraseña?".';
    }

    public function verify(Request $request)
    {
        $validated = $request->validate([
            'challenge' => ['required', 'string'],
            'code'      => ['required', 'string', 'max:12'],
            'trust'     => ['sometimes', 'boolean'],
        ], ['code.required' => 'Escribe el código que te llegó al correo.']);

        $account = AccountSecurity::verify($request, $validated['challenge'], $validated['code']);
        $active = $account instanceof Customer ? $account->portal_enabled !== false : $account->status === 'active';
        abort_unless($active && (is_null($account->tenant_id) || $account->tenant?->isActive()), 403, 'Tu cuenta ya no tiene acceso. Comunícate con el administrador.');

        return response()->json(self::issue($request, $account) + (!empty($validated['trust']) ? ['device_token' => AccountSecurity::trust($request, $account)] : []));
    }

    public function resend(Request $request)
    {
        $challenge = $request->validate(['challenge' => ['required', 'string']])['challenge'];

        try {
            return response()->json(AccountSecurity::resend($request, $challenge));
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw $e;
        } catch (\Throwable $e) {
            report($e);
            \App\Support\PlatformMail::markFailed($e, 'un código de ingreso');

            return response()->json(['message' => 'No pudimos enviar otro código. Pulsa "Volver" y escribe tu contraseña de nuevo: esta vez entrarás sin código.', 'code' => 'CODE_NOT_SENT'], 503);
        }
    }

    public static function issue(Request $request, User|Customer $account): array
    {
        $token = $account->createToken('auth_token')->plainTextToken;

        Audit::log($request, 'auth.login', [
            'actor'        => $account,
            'tenant'       => $account->tenant_id,
            'impersonator' => null,
            'entity_type'  => $account instanceof Customer ? 'customer' : 'user',
            'entity_id'    => $account->id,
        ]);

        if ($account instanceof Customer || \Illuminate\Support\Facades\Schema::hasColumn('users', 'last_login_at')) {
            $account->forceFill(['last_login_at' => now()])->saveQuietly();
        }

        return [
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => self::sessionUser($account),
        ];
    }

    public static function sessionUser(User|Customer $account): array
    {
        $isCustomer = $account instanceof Customer;

        return [
            'id'           => $account->id,
            'tenant_id'    => $account->tenant_id,
            'first_name'   => $account->first_name,
            'last_name'    => $account->last_name,
            'email'        => $account->email,
            'phone'        => $account->phone,
            'is_owner'     => $isCustomer ? false : (bool) $account->is_owner,
            'account_type' => $isCustomer ? 'customer' : 'staff',
            'status'       => $isCustomer ? 'active' : $account->status,
            'must_change_password' => (bool) ($account->must_change_password ?? false),
        ];
    }

    public function logout(Request $request)
    {
        $account = $request->user();

        Audit::log($request, $request->input('reason') === 'idle' ? 'auth.idle_logout' : 'auth.logout', [
            'tenant'      => $account->tenant_id,
            'entity_type' => $account instanceof Customer ? 'customer' : 'user',
            'entity_id'   => $account->id,
        ]);

        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Sesión cerrada correctamente']);
    }
}
