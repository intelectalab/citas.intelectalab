<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AccountSecurity;
use App\Support\Audit;
use Illuminate\Http\Request;

class AccountDeviceController extends Controller
{
    public function index(Request $request)
    {
        if (!AccountSecurity::ready()) {
            return response()->json(['ready' => false, 'devices' => [], 'trust_days' => AccountSecurity::TRUST_DAYS]);
        }

        return response()->json([
            'ready'      => true,
            'trust_days' => AccountSecurity::TRUST_DAYS,
            'devices'    => AccountSecurity::devices($request->user(), $request->header('X-Trusted-Device')),
        ]);
    }

    public function profile(Request $request)
    {
        $user = $request->user();
        $tenant = $user->tenant;
        $role = $user->tenant_id ? \App\Support\Access::roleOf($user) : null;
        $scope = \App\Support\Access::scopeOf($user);
        $zone = $tenant?->zone() ?? 'America/Lima';
        $previous = AccountSecurity::ready() && \Illuminate\Support\Facades\Schema::hasTable('audit_events')
            ? \Illuminate\Support\Facades\DB::table('audit_events')->where('actor_user_id', $user->id)->where('action', 'auth.login')->orderByDesc('created_at')->skip(1)->value('created_at')
            : null;

        return response()->json([
            'name'          => trim("{$user->first_name} {$user->last_name}"),
            'email'         => $user->email,
            'phone'         => $user->phone,
            'company'       => $tenant?->name,
            'role'          => $role?->name ?? ($user->is_owner ? 'Dueño' : ($user->tenant_id ? 'Sin rol' : ($user->isSaasAdmin() ? 'Administrador de la plataforma' : 'Soporte de la plataforma'))),
            'scope'         => \App\Support\Access::SCOPES[$scope] ?? null,
            'branch'        => $user->branch_id ? \Illuminate\Support\Facades\DB::table('branches')->where('id', $user->branch_id)->value('name') : null,
            'code_required' => AccountSecurity::codeRequired($user),
            'last_login_at' => ($previous ? \Illuminate\Support\Carbon::parse($previous) : $user->last_login_at)?->setTimezone($zone)->toIso8601String(),
        ]);
    }

    public function destroy(Request $request, string $device)
    {
        abort_unless(AccountSecurity::ready(), 404);

        $removed = AccountSecurity::forgetDevice($request->user(), $device);
        abort_unless($removed, 404, 'Ese equipo ya no está en tu lista.');

        Audit::log($request, 'auth.device_removed', [
            'tenant'      => $request->user()->tenant_id,
            'entity_type' => 'user',
            'entity_id'   => $request->user()->id,
        ]);

        return response()->json(['message' => 'Listo: ese equipo te pedirá el código la próxima vez que entres.']);
    }
}
