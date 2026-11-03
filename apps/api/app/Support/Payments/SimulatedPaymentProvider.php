<?php

namespace App\Support\Payments;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SimulatedPaymentProvider implements PaymentProvider
{
    public function key(): string
    {
        return 'simulated';
    }

    public function simulated(): bool
    {
        return true;
    }

    public function checkout(object $link): array
    {
        return ['external_id' => 'sim_' . Str::lower(Str::random(14))];
    }

    public function status(string $externalId): ?array
    {
        return null;
    }

    public function test(): array
    {
        return ['ok' => true, 'message' => 'La simulación responde. Recuerda: con la simulación no se cobra dinero de verdad.'];
    }

    public function refund(object $payment, float $amount): array
    {
        return [
            'ok'          => true,
            'external_id' => 'sim_rf_' . Str::lower(Str::random(12)),
            'message'     => 'Simulación: la devolución no pasó por una pasarela real.',
        ];
    }

    public static function sign(string $body): string
    {
        return hash_hmac('sha256', $body, (string) config('app.key'));
    }

    public function parse(Request $request): ?array
    {
        $body = $request->getContent();
        $signature = (string) $request->header('X-Signature');

        if ($signature === '' || !hash_equals(self::sign($body), $signature)) {
            return null;
        }

        $data = json_decode($body, true);

        if (!is_array($data) || empty($data['event_id']) || empty($data['type']) || empty($data['reference'])) {
            return null;
        }

        return [
            'event_id'  => (string) $data['event_id'],
            'type'      => (string) $data['type'],
            'reference' => (string) $data['reference'],
            'amount'    => isset($data['amount']) ? (float) $data['amount'] : null,
            'payload'   => $data,
        ];
    }
}
