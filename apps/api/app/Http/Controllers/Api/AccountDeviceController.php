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
