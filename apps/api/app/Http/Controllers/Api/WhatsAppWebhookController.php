<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Support\WhatsApp;
use Illuminate\Http\Request;

class WhatsAppWebhookController extends Controller
{
    private function config(string $tenantId): array
    {
        abort_unless(WhatsApp::ready(), 503);
        $tenant = Tenant::find($tenantId);
        abort_unless($tenant, 404);
        $config = WhatsApp::config($tenant->id);
        abort_unless($config, 404);

        return [$tenant, $config];
    }

    public function verify(Request $request, string $tenant)
    {
        [, $config] = $this->config($tenant);
        $token = (string) ($config['verify_token'] ?? '');

        if ($request->query('hub_mode') === 'subscribe' && $token !== '' && hash_equals($token, (string) $request->query('hub_verify_token'))) {
            return response((string) $request->query('hub_challenge'), 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    public function receive(Request $request, string $tenant)
    {
        [$company, $config] = $this->config($tenant);
        $raw = $request->getContent();

        if (!WhatsApp::signatureValid($config, $raw, $request->header('X-Hub-Signature-256'), $request->header('X-Webhook-Token'))) {
            \App\Support\IntegrationLog::write($company->id, 'whatsapp', (string) $config['provider'], 'webhook', false, 'Llegó un aviso sin la firma correcta: no se tomó en cuenta.');

            return response()->json(['message' => 'Firma no válida.'], 403);
        }

        $payload = json_decode($raw, true);

        if (!is_array($payload)) {
            return response()->json(['message' => 'Contenido no válido.'], 400);
        }

        return response()->json(['ok' => true] + WhatsApp::receive($company, $payload));
    }
}
