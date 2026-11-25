<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\GatewayWebhooks;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class GatewayWebhookController extends Controller
{
    private function owner(Request $request, bool $edit): string
    {
        $user = $request->user();
        $tenant = $user->tenant;
        abort_unless($tenant, 403);
        abort_unless($user->allows($edit ? 'settings.edit' : 'settings.view'), 403, $edit ? 'Tu cuenta no tiene permiso para cambiar la configuración de la empresa.' : 'Tu cuenta no tiene permiso para ver la configuración de la empresa.');

        return $tenant->id;
    }

    public function index(Request $request)
    {
        return response()->json(['ready' => GatewayWebhooks::ready(), 'webhooks' => GatewayWebhooks::list($this->owner($request, false))]);
    }

    public function retry(Request $request, string $webhook)
    {
        $owner = $this->owner($request, true);

        return response()->json($this->doRetry($request, $owner, $webhook) + ['webhooks' => GatewayWebhooks::list($owner)]);
    }

    public function platformIndex(Request $request)
    {
        return response()->json(['ready' => GatewayWebhooks::ready(), 'webhooks' => GatewayWebhooks::list('platform')]);
    }

    public function platformRetry(Request $request, string $webhook)
    {
        return response()->json($this->doRetry($request, 'platform', $webhook) + ['webhooks' => GatewayWebhooks::list('platform')]);
    }

    private function doRetry(Request $request, string $owner, string $id): array
    {
        $row = GatewayWebhooks::find($owner, $id);
        abort_unless($row, 404, 'No se encontró ese aviso.');

        if (!in_array($row->status, ['failed', 'gave_up', 'ignored'], true)) {
            throw ValidationException::withMessages(['webhook' => 'Este aviso ya se procesó: no hace falta reintentarlo.']);
        }

        $result = GatewayWebhooks::retry($row, fn (Request $req) => app(PaymentController::class)->processWebhook($req, $row->provider, $owner));

        if ($owner !== 'platform') {
            Audit::log($request, 'settings.changed', [
                'tenant' => $owner, 'entity_type' => 'settings', 'entity_label' => 'Aviso de ' . $result['provider_label'],
                'after'  => ['webhook_retry' => $result['status_label']],
            ]);
        }

        return ['webhook' => $result, 'message' => $result['status'] === 'processed' ? 'Listo: el aviso se procesó.' : 'Se volvió a intentar: ' . mb_strtolower($result['status_label']) . '.'];
    }
}
