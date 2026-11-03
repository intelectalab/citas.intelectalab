<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Support\AccountSecurity;
use App\Support\PlatformMail;
use Illuminate\Http\Request;

class PasswordController extends Controller
{
    public const SENT = 'Si ese correo tiene una cuenta, te enviamos un enlace para crear tu contraseña nueva. Revisa tu bandeja de entrada y la de spam; el enlace vale 60 minutos.';

    public function forgot(Request $request)
    {
        $email = $request->validate(['email' => 'required|email|max:190'], ['email.required' => 'Escribe tu correo.', 'email.email' => 'Ese correo no es válido.'])['email'];

        abort_unless(AccountSecurity::ready(), 503, 'Falta actualizar la base de datos: ejecuta php artisan migrate.');

        if (!PlatformMail::ready()) {
            return response()->json(['message' => 'La recuperación por correo todavía no está disponible. Pídele al administrador de tu empresa (o a la sede donde te atiendes) que te asigne una contraseña nueva.', 'code' => 'MAIL_NOT_READY'], 503);
        }

        if (AccountSecurity::forgot($request, $email) === false) {
            return response()->json(['message' => 'No pudimos enviar el correo en este momento. Inténtalo más tarde o pídele al administrador de tu empresa una contraseña nueva.', 'code' => 'MAIL_FAILED'], 503);
        }

        return response()->json(['message' => self::SENT]);
    }

    public function show(string $token)
    {
        $info = AccountSecurity::ready() ? AccountSecurity::resetInfo($token) : null;
        abort_unless($info, 404, 'Este enlace ya no sirve: venció o ya se usó. Pide uno nuevo.');

        return response()->json($info);
    }

    public function reset(Request $request)
    {
        $validated = $request->validate([
            'token'    => 'required|string|max:100',
            'password' => 'required|string|min:8|max:100|confirmed',
        ], [
            'password.required'  => 'Escribe tu contraseña nueva.',
            'password.min'       => 'La contraseña debe tener al menos 8 caracteres.',
            'password.confirmed' => 'Las dos contraseñas no coinciden.',
        ]);

        $account = AccountSecurity::reset($request, $validated['token'], $validated['password']);

        return response()->json([
            'message' => 'Listo: tu contraseña cambió. Ya puedes entrar con ella.',
            'email'   => $account->email,
        ]);
    }
}
