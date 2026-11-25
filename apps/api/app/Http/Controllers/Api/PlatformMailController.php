<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditEvent;
use App\Support\PlatformMail;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PlatformMailController extends Controller
{
    public function show()
    {
        return response()->json(PlatformMail::present());
    }

    public function update(Request $request)
    {
        $validated = $request->validate([
            'transport'    => ['nullable', Rule::in(['smtp', 'api'])],
            'service'      => ['required_if:transport,api', 'nullable', Rule::in(array_keys(\App\Support\Mail\ApiTransport::SERVICES))],
            'region'       => ['nullable', 'string', 'max:30', 'regex:/^[a-z]{2}(-[a-z]+)+-\d$/'],
            'host'         => 'exclude_if:transport,api|required|string|max:190',
            'port'         => 'exclude_if:transport,api|required|integer|min:1|max:65535',
            'security'     => ['exclude_if:transport,api', 'required', Rule::in(['ssl', 'tls'])],
            'username'     => 'nullable|string|max:190',
            'password'     => 'nullable|string|max:255',
            'from_address' => 'required|email|max:190',
            'from_name'    => 'nullable|string|max:100',
        ], [
            'host.required'         => 'Escribe el servidor de salida, por ejemplo mail.tudominio.com.',
            'from_address.required' => 'Escribe el correo desde el que se envía.',
            'from_address.email'    => 'Ese correo no es válido.',
        ]);

        $api = ($validated['transport'] ?? 'smtp') === 'api';
        $hasKey = !empty(PlatformMail::config()['password'] ?? null) && ((PlatformMail::config()['transport'] ?? 'smtp') === ($api ? 'api' : 'smtp'));

        if (!$hasKey && trim((string) ($validated['password'] ?? '')) === '' && ($api || !empty($validated['username']))) {
            throw ValidationException::withMessages(['password' => $api ? 'Pega la clave (API key) del servicio de correo.' : 'Escribe la contraseña del correo.']);
        }

        if ($api && ($validated['service'] ?? null) === 'ses' && empty($validated['username'])) {
            throw ValidationException::withMessages(['username' => 'Escribe el identificador de la clave de acceso de AWS (Access key ID).']);
        }

        $before = PlatformMail::present();
        PlatformMail::save($validated);
        $after = PlatformMail::present();

        AuditEvent::record($request, 'platform.mail_changed', null, array_intersect_key($before, array_flip(['transport', 'service', 'host', 'port', 'security', 'username', 'from_address', 'from_name'])), array_intersect_key($after, array_flip(['transport', 'service', 'host', 'port', 'security', 'username', 'from_address', 'from_name'])) + (trim((string) ($validated['password'] ?? '')) !== '' ? ['password' => 'Cambiada'] : []));

        return response()->json($after);
    }

    public function test(Request $request)
    {
        $to = $request->validate(['to' => 'required|email|max:190'], ['to.required' => 'Escribe a qué correo enviar la prueba.', 'to.email' => 'Ese correo no es válido.'])['to'];

        if (!PlatformMail::configured()) {
            throw ValidationException::withMessages(['to' => 'Primero guarda los datos del correo.']);
        }

        return response()->json(PlatformMail::test($to) + ['mail' => PlatformMail::present()]);
    }

    public function destroy(Request $request)
    {
        PlatformMail::remove();
        AuditEvent::record($request, 'platform.mail_changed', null, [], ['host' => 'Sin correo']);

        return response()->json(PlatformMail::present());
    }
}
