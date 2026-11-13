<?php

return [
    'simulation' => env('PAYMENTS_SIMULATION', in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),

    'mercadopago' => [
        'api' => env('MERCADOPAGO_API', 'https://api.mercadopago.com'),
    ],

    'culqi' => [
        'api'    => env('CULQI_API', 'https://api.culqi.com/v2'),
        'script' => env('CULQI_SCRIPT', 'https://js.culqi.com/checkout-js'),
    ],
];
