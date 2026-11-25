<?php

namespace App\Support\AuditActions;

class CustomerActions
{
    public const ACTIONS = [
        'customer.call_logged'     => ['Registró una llamada con un cliente', 'customers'],
        'record.created_without_appointment' => ['Abrió una ficha de atención sin cita', 'records'],
    ];

    public const FIELDS = [
        'weight_kg'       => 'Peso (kg)',
        'color'           => 'Color',
        'sterilized'      => 'Esterilizado',
        'microchip'       => 'Microchip',
        'relationship'    => 'Parentesco',
        'is_confidential' => 'Confidencial',
    ];
}
