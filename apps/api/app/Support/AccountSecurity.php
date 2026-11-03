<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountSecurity
{
    public const MAX_ATTEMPTS = 5;

    public const LOCK_MINUTES = 15;

    public const CODE_MINUTES = 10;

    public const CODE_ATTEMPTS = 5;

    public const RESEND_SECONDS = 60;

    public const TRUST_DAYS = 30;

    public const RESET_MINUTES = 60;

    public const INVITE_DAYS = 3;

    public static function ready(): bool
    {
        static $ready = null;

        return $ready ??= Schema::hasTable('account_tokens') && Schema::hasColumn('users', 'locked_until');
    }

    public static function type(User|Customer $account): string
    {
        return $account instanceof Customer ? 'customer' : 'user';
    }

    private static function hash(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    private static function find(string $type, string $id): User|Customer|null
    {
        return $type === 'customer' ? Customer::with('tenant')->find($id) : User::with('tenant')->find($id);
    }

    private static function tenantOf(User|Customer $account): ?Tenant
    {
        return $account->tenant_id ? Tenant::with(['settings', 'businessType'])->find($account->tenant_id) : null;
    }

    public static function lockedMinutes(User|Customer $account): ?int
    {
        if (!self::ready() || !$account->locked_until || Carbon::parse($account->locked_until)->isPast()) {
            return null;
        }

        return max(1, (int) ceil(now()->diffInSeconds(Carbon::parse($account->locked_until)) / 60));
    }

    public static function failed(Request $request, User|Customer $account): void
    {
        if (!self::ready() || self::lockedMinutes($account)) {
            return;
        }

        $count = (int) $account->failed_logins + 1;
        $values = ['failed_logins' => $count];

        if ($count >= self::MAX_ATTEMPTS) {
            $values = ['failed_logins' => 0, 'locked_until' => now()->addMinutes(self::LOCK_MINUTES)];
        }

        $account->forceFill($values)->saveQuietly();

        if ($count >= self::MAX_ATTEMPTS) {
            Audit::log($request, 'auth.locked', [
                'actor'        => $account,
                'tenant'       => $account->tenant_id,
                'impersonator' => null,
                'entity_type'  => self::type($account),
                'entity_id'    => $account->id,
            ]);

            $tenant = self::tenantOf($account);
            PlatformMail::trySend(
                $account->email,
                'Bloqueamos tu cuenta por ' . self::LOCK_MINUTES . ' minutos',
                '<p>Hola ' . e($account->first_name) . ':</p><p>Alguien escribió mal la contraseña de tu cuenta ' . self::MAX_ATTEMPTS . ' veces seguidas' . ($tenant ? ' en <strong>' . e($tenant->name) . '</strong>' : '') . '. Por seguridad la bloqueamos ' . self::LOCK_MINUTES . ' minutos.</p><p>Si fuiste tú, espera y vuelve a intentarlo, o usa "¿Olvidaste tu contraseña?". Si no fuiste tú, cambia tu contraseña cuando vuelvas a entrar.</p>',
                $tenant,
            );
        }
    }

    public static function succeeded(User|Customer $account): void
    {
        if (self::ready() && ($account->failed_logins || $account->locked_until)) {
            $account->forceFill(['failed_logins' => 0, 'locked_until' => null])->saveQuietly();
        }
    }

    public static function unlock(User|Customer $account): void
    {
        $account->forceFill(['failed_logins' => 0, 'locked_until' => null])->saveQuietly();
    }

    public static function codeRequired(User|Customer $account): bool
    {
        if (!self::ready() || $account instanceof Customer || !PlatformMail::ready()) {
            return false;
        }

        if ($account->mfa_mode === 'off') {
            return false;
        }

        if ($account->mfa_mode === 'required') {
            return true;
        }

        if (!$account->tenant_id) {
            return ($account->isSaasAdmin() || $account->isSaasSupport()) && (bool) \App\Models\PlatformSetting::value('mfa_platform');
        }

        $tenant = self::tenantOf($account);

        return $tenant && $account->isTenantAdmin() && self::adminRule($tenant);
    }

    public static function adminRule(Tenant $tenant): bool
    {
        return (bool) ($tenant->rules()['security.mfa_admin'] ?? \App\Models\PlatformSetting::value('mfa_tenant_admins'));
    }

    public static function trusted(User|Customer $account, ?string $device): bool
    {
        if (!$device || !self::ready()) {
            return false;
        }

        return DB::table('account_tokens')
            ->where('kind', 'trusted_device')
            ->where('account_type', self::type($account))
            ->where('account_id', $account->id)
            ->where('token_hash', self::hash($device))
            ->where('expires_at', '>', now())
            ->exists();
    }

    public static function challenge(Request $request, User|Customer $account): array
    {
        DB::table('account_tokens')
            ->where('kind', 'login_code')
            ->where('account_type', self::type($account))
            ->where('account_id', $account->id)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);

        $id = (string) Str::uuid();
        $code = self::code();

        DB::table('account_tokens')->insert([
            'id'           => $id,
            'kind'         => 'login_code',
            'account_type' => self::type($account),
            'account_id'   => $account->id,
            'token_hash'   => self::hash($id . ':' . $code),
            'expires_at'   => now()->addMinutes(self::CODE_MINUTES),
            'sent_at'      => now(),
            'ip'           => mb_substr((string) $request->ip(), 0, 45),
            'user_agent'   => mb_substr((string) $request->userAgent(), 0, 255),
            'created_at'   => now(),
        ]);

        self::sendCode($account, $code);

        return [
            'requires_code' => true,
            'challenge'     => $id,
            'email_hint'    => PlatformMail::mask($account->email),
            'minutes'       => self::CODE_MINUTES,
            'trust_days'    => self::TRUST_DAYS,
        ];
    }

    private static function code(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private static function sendCode(User|Customer $account, string $code): void
    {
        $tenant = self::tenantOf($account);

        PlatformMail::send(
            $account->email,
            "{$code} es tu código para entrar",
            '<p>Hola ' . e($account->first_name) . ':</p><p>Tu código para entrar' . ($tenant ? ' a <strong>' . e($tenant->name) . '</strong>' : ' a ' . e(PlatformMail::productName())) . ' es:</p>'
                . '<h2>' . $code . '</h2>'
                . '<p>Vence en ' . self::CODE_MINUTES . ' minutos. Si no intentaste entrar, ignora este correo y cambia tu contraseña.</p>',
            $tenant,
        );
    }

    private static function pending(string $challenge): ?object
    {
        if (!Str::isUuid($challenge)) {
            return null;
        }

        return DB::table('account_tokens')->where('id', $challenge)->where('kind', 'login_code')->first();
    }

    public static function resend(Request $request, string $challenge): array
    {
        $row = self::pending($challenge);

        if (!$row || $row->used_at || Carbon::parse($row->expires_at)->isPast()) {
            throw ValidationException::withMessages(['code' => 'Este ingreso venció. Vuelve a escribir tu contraseña.']);
        }

        $wait = self::RESEND_SECONDS - (int) Carbon::parse($row->sent_at)->diffInSeconds(now());

        if ($wait > 0) {
            throw ValidationException::withMessages(['code' => "Espera {$wait} segundos para pedir otro código."]);
        }

        $account = self::find($row->account_type, $row->account_id);
        abort_unless($account, 404);
        $code = self::code();

        DB::table('account_tokens')->where('id', $row->id)->update([
            'token_hash' => self::hash($row->id . ':' . $code),
            'attempts'   => 0,
            'expires_at' => now()->addMinutes(self::CODE_MINUTES),
            'sent_at'    => now(),
        ]);

        self::sendCode($account, $code);

        return ['message' => 'Te enviamos un código nuevo a ' . PlatformMail::mask($account->email) . '.'];
    }

    public static function verify(Request $request, string $challenge, string $code): User|Customer
    {
        $row = self::pending($challenge);

        if (!$row || $row->used_at || Carbon::parse($row->expires_at)->isPast()) {
            throw ValidationException::withMessages(['code' => 'El código venció. Vuelve a escribir tu contraseña para recibir otro.']);
        }

        $account = self::find($row->account_type, $row->account_id);
        abort_unless($account, 404);

        if (!hash_equals($row->token_hash, self::hash($row->id . ':' . preg_replace('/\D/', '', $code)))) {
            $attempts = (int) $row->attempts + 1;
            DB::table('account_tokens')->where('id', $row->id)->update(['attempts' => $attempts] + ($attempts >= self::CODE_ATTEMPTS ? ['used_at' => now()] : []));

            Audit::log($request, 'auth.code_failed', [
                'actor'        => $account,
                'tenant'       => $account->tenant_id,
                'impersonator' => null,
                'entity_type'  => self::type($account),
                'entity_id'    => $account->id,
            ]);

            if ($attempts >= self::CODE_ATTEMPTS) {
                self::failed($request, $account);
                throw ValidationException::withMessages(['code' => 'Escribiste mal el código ' . self::CODE_ATTEMPTS . ' veces. Vuelve a escribir tu contraseña para recibir otro.']);
            }

            $left = self::CODE_ATTEMPTS - $attempts;
            throw ValidationException::withMessages(['code' => "Ese código no es correcto. Te quedan {$left} " . ($left === 1 ? 'intento' : 'intentos') . '.']);
        }

        DB::table('account_tokens')->where('id', $row->id)->update(['used_at' => now()]);

        return $account;
    }

    public static function trust(Request $request, User|Customer $account): string
    {
        $device = Str::random(48);

        DB::table('account_tokens')->insert([
            'id'           => (string) Str::uuid(),
            'kind'         => 'trusted_device',
            'account_type' => self::type($account),
            'account_id'   => $account->id,
            'token_hash'   => self::hash($device),
            'expires_at'   => now()->addDays(self::TRUST_DAYS),
            'ip'           => mb_substr((string) $request->ip(), 0, 45),
            'user_agent'   => mb_substr((string) $request->userAgent(), 0, 255),
            'created_at'   => now(),
        ]);

        return $device;
    }

    public static function forgetDevices(User|Customer $account): void
    {
        DB::table('account_tokens')->where('kind', 'trusted_device')->where('account_type', self::type($account))->where('account_id', $account->id)->delete();
    }

    public static function forgot(Request $request, string $email): ?bool
    {
        $email = mb_strtolower(trim($email));
        $accounts = User::with('tenant')->whereRaw('lower(email) = ?', [$email])->where('status', 'active')->get()
            ->concat(Customer::with('tenant')->whereRaw('lower(email) = ?', [$email])->whereNotNull('password_hash')->where(fn ($q) => $q->whereNull('portal_enabled')->orWhere('portal_enabled', true))->get())
            ->filter(fn ($account) => !$account->tenant_id || $account->tenant?->isActive())
            ->values();

        if ($accounts->isEmpty()) {
            return null;
        }

        $recent = DB::table('account_tokens')->where('kind', 'reset')
            ->whereIn('account_id', $accounts->pluck('id'))
            ->where('created_at', '>', now()->subMinute())
            ->exists();

        if ($recent) {
            return null;
        }

        $links = [];

        foreach ($accounts as $account) {
            DB::table('account_tokens')->where('kind', 'reset')->where('account_type', self::type($account))->where('account_id', $account->id)->whereNull('used_at')->update(['used_at' => now()]);

            $token = Str::random(64);
            DB::table('account_tokens')->insert([
                'id'           => (string) Str::uuid(),
                'kind'         => 'reset',
                'account_type' => self::type($account),
                'account_id'   => $account->id,
                'token_hash'   => self::hash($token),
                'expires_at'   => now()->addMinutes(self::RESET_MINUTES),
                'sent_at'      => now(),
                'ip'           => mb_substr((string) $request->ip(), 0, 45),
                'user_agent'   => mb_substr((string) $request->userAgent(), 0, 255),
                'created_at'   => now(),
            ]);

            $where = $account->tenant?->name ?? 'Administración de ' . PlatformMail::productName();
            $who = $account instanceof Customer ? 'tu cuenta del portal' : 'tu cuenta del equipo';
            $links[] = ['url' => rtrim((string) config('app.frontend_url'), '/') . '/restablecer/' . $token, 'label' => "{$where} ({$who})"];

            Audit::log($request, 'auth.reset_requested', [
                'actor'        => $account,
                'tenant'       => $account->tenant_id,
                'impersonator' => null,
                'entity_type'  => self::type($account),
                'entity_id'    => $account->id,
            ]);
        }

        $first = $accounts->first();
        $tenant = $accounts->count() === 1 ? self::tenantOf($first) : null;
        $list = count($links) === 1
            ? '<p><a href="' . e($links[0]['url']) . '">Crear mi contraseña nueva</a></p>'
            : '<p>Tu correo tiene varias cuentas. Elige la que quieres cambiar:</p><ul>' . collect($links)->map(fn ($l) => '<li><a href="' . e($l['url']) . '">' . e($l['label']) . '</a></li>')->implode('') . '</ul>';

        return PlatformMail::trySend(
            $first->email,
            'Crea tu contraseña nueva',
            '<p>Hola ' . e($first->first_name) . ':</p><p>Pediste cambiar tu contraseña' . ($tenant ? ' de <strong>' . e($tenant->name) . '</strong>' : '') . '.</p>' . $list
                . '<p>El enlace vale ' . self::RESET_MINUTES . ' minutos y sirve una sola vez. Si no lo pediste, ignora este correo: tu contraseña sigue igual.</p>',
            $tenant,
        );
    }

    private static function resetRow(string $token): ?object
    {
        if (strlen($token) < 40 || strlen($token) > 100) {
            return null;
        }

        $row = DB::table('account_tokens')->whereIn('kind', ['reset', 'invite'])->where('token_hash', self::hash($token))->first();

        return $row && !$row->used_at && Carbon::parse($row->expires_at)->isFuture() ? $row : null;
    }

    public static function resetInfo(string $token): ?array
    {
        $row = self::resetRow($token);
        $account = $row ? self::find($row->account_type, $row->account_id) : null;

        if (!$account) {
            return null;
        }

        return [
            'email'   => PlatformMail::mask($account->email),
            'where'   => $account->tenant?->name ?? 'Administración de la plataforma',
            'account' => $row->account_type === 'customer' ? 'customer' : 'staff',
            'invite'  => $row->kind === 'invite',
            'name'    => $account->first_name,
        ];
    }

    public static function reset(Request $request, string $token, string $password): array
    {
        $row = self::resetRow($token);
        $account = $row ? self::find($row->account_type, $row->account_id) : null;

        if (!$account) {
            throw ValidationException::withMessages(['token' => 'Este enlace ya no sirve: venció o ya se usó. Pide uno nuevo.']);
        }

        DB::transaction(function () use ($row, $account, $password) {
            DB::table('account_tokens')->where('id', $row->id)->update(['used_at' => now()]);
            DB::table('account_tokens')->whereIn('kind', ['reset', 'invite'])->where('account_type', $row->account_type)->where('account_id', $account->id)->whereNull('used_at')->update(['used_at' => now()]);

            $values = ['password_hash' => Hash::make($password), 'failed_logins' => 0, 'locked_until' => null];

            if ($account instanceof Customer || Schema::hasColumn('users', 'must_change_password')) {
                $values['must_change_password'] = false;
            }

            $account->forceFill($values)->saveQuietly();
            $account->tokens()->delete();
        });

        self::forgetDevices($account);

        Audit::log($request, $row->kind === 'invite' ? 'auth.invite_accepted' : 'auth.password_reset', [
            'actor'        => $account,
            'tenant'       => $account->tenant_id,
            'impersonator' => null,
            'entity_type'  => self::type($account),
            'entity_id'    => $account->id,
        ]);

        return ['account' => $account, 'invite' => $row->kind === 'invite'];
    }

    public static function invite(User $user, Tenant $tenant, bool $card): bool
    {
        if (!self::ready() || !PlatformMail::ready()) {
            return false;
        }

        DB::table('account_tokens')->where('kind', 'invite')->where('account_type', 'user')->where('account_id', $user->id)->whereNull('used_at')->update(['used_at' => now()]);
        $token = Str::random(64);
        DB::table('account_tokens')->insert([
            'id'           => (string) Str::uuid(),
            'kind'         => 'invite',
            'account_type' => 'user',
            'account_id'   => $user->id,
            'token_hash'   => self::hash($token),
            'expires_at'   => now()->addDays(self::INVITE_DAYS),
            'sent_at'      => now(),
            'created_at'   => now(),
        ]);

        $url = rtrim((string) config('app.frontend_url'), '/') . '/restablecer/' . $token;
        $tenant->loadMissing(['settings', 'businessType']);

        return PlatformMail::trySend(
            $user->email,
            'Te damos la bienvenida a ' . $tenant->name,
            '<p>Hola ' . e($user->first_name) . ':</p><p>Ya está lista la cuenta de <strong>' . e($tenant->name) . '</strong> en ' . e(PlatformMail::productName()) . '. Entras con este correo y una contraseña que eliges tú.</p>'
                . '<p><a href="' . e($url) . '">Crear mi contraseña</a></p>'
                . ($card ? '<p>Después te pediremos autorizar tu tarjeta en Mercado Pago para el pago del plan. Ahí la escribes tú; nadie más la ve.</p>' : '')
                . '<p>El enlace vale ' . self::INVITE_DAYS . ' días. Si vence, pídenos uno nuevo.</p>',
            $tenant,
        );
    }

    public static function present(User|Customer $account): array
    {
        $tenantRequired = $account instanceof User && $account->tenant_id && $account->isTenantAdmin()
            && ($tenant = self::tenantOf($account)) && self::adminRule($tenant);

        return [
            'mfa_mode'      => self::ready() ? ($account->mfa_mode ?? null) : null,
            'mfa_active'    => self::ready() && $account instanceof User && $account->mfa_mode !== 'off' && ($account->mfa_mode === 'required' || $tenantRequired),
            'locked_until'  => self::lockedMinutes($account) ? Carbon::parse($account->locked_until)->toIso8601String() : null,
            'locked_minutes' => self::lockedMinutes($account),
        ];
    }
}
