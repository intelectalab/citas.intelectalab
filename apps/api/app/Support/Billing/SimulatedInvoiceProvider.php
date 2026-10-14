<?php

namespace App\Support\Billing;

class SimulatedInvoiceProvider implements InvoiceProvider
{
    public function key(): string
    {
        return 'simulated';
    }

    public function send(array $document): array
    {
        return [
            'status'      => 'accepted',
            'message'     => 'Simulación: el comprobante no se envió a SUNAT.',
            'external_id' => 'SIM-' . ($document['serie'] ?? '') . '-' . ($document['number'] ?? ''),
            'simulated'   => true,
        ];
    }
}
