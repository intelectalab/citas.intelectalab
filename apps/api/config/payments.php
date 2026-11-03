<?php

return [
    'simulation' => env('PAYMENTS_SIMULATION', in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),

    'mercadopago' => [
        'api' => env('MERCADOPAGO_API', 'https://api.mercadopago.com'),
    ],
];
