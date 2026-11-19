<?php

namespace App\Support\Billing;

class SimulatedInvoiceProvider implements InvoiceProvider
{
    public function key(): string
    {
        return 'simulated';
    }

    public function real(): bool
    {
        return false;
    }

    public function send(array $document): array
    {
        return [
            'status'      => 'accepted',
            'received'    => true,
            'message'     => 'Simulación: el comprobante no se envió a SUNAT.',
            'external_id' => 'SIM-' . ($document['serie'] ?? '') . '-' . ($document['number'] ?? ''),
            'simulated'   => true,
        ];
    }

    public function status(array $document): array
    {
        return $this->send($document);
    }

    public function void(array $document, string $reason): array
    {
        return ['status' => 'accepted', 'message' => 'Simulación: la baja no se envió a SUNAT.', 'ticket' => null, 'simulated' => true];
    }

    public function voidStatus(array $document): array
    {
        return $this->void($document, '');
    }

    public function test(array $series): array
    {
        return ['ok' => true, 'message' => 'Estás en simulación: los comprobantes se numeran y se imprimen, pero no se envían a SUNAT.'];
    }
}
