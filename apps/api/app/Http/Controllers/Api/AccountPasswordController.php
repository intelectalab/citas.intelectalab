<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\AccountSecurity;
use App\Support\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AccountPasswordController extends Controller
{
    public function update(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'current_password' => 'required|string',
            'password'         => 'required|string|min:8|max:100|confirmed',
        ], [
            'current_password.required' => 'Escribe la contraseña con la que entraste.',
            'password.required'         => 'Escribe tu contraseña nueva.',
            'password.min'              => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed'        => 'Las dos contraseñas no coinciden.',
        ]);

        if (!Hash::check($validated['current_password'], $user->getAuthPassword())) {
            throw ValidationException::withMessages(['current_password' => 'La contraseña con la que entraste no es correcta.']);
        }

        if (Hash::check($validated['password'], $user->getAuthPassword())) {
            throw ValidationException::withMessages(['password' => 'Elige una contraseña distinta de la que te dieron.']);
        }

        $current = $user->currentAccessToken()?->id;
        $user->forceFill(['password_hash' => Hash::make($validated['password']), 'must_change_password' => false])->saveQuietly();
        $user->tokens()->when($current, fn ($q) => $q->where('id', '!=', $current))->delete();

        if (AccountSecurity::ready()) {
            AccountSecurity::forgetDevices($user);
        }

        Audit::log($request, 'auth.password_changed', [
            'tenant'      => $user->tenant_id,
            'entity_type' => 'user',
            'entity_id'   => $user->id,
        ]);

        return response()->json(['message' => 'Listo: tu contraseña cambió.']);
    }
}
