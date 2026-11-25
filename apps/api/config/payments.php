<?php

return [
    'simulation' => env('PAYMENTS_SIMULATION', in_array(env('APP_ENV', 'production'), ['local', 'testing'], true)),

    'mercadopago' => [
        'api' => env('MERCADOPAGO_API', 'https://api.mercadopago.com'),
    ],

    'culqi' => [
        'api'      => env('CULQI_API', 'https://api.culqi.com/v2'),
        'script'   => env('CULQI_SCRIPT', 'https://js.culqi.com/checkout-js'),
        'three_ds' => env('CULQI_3DS_SCRIPT', 'https://3ds.culqi.com'),
    ],

    'niubiz' => [
        'api'                => env('NIUBIZ_API', 'https://apiprod.vnforapps.com'),
        'sandbox_api'        => env('NIUBIZ_SANDBOX_API', 'https://apisandbox.vnforappstest.com'),
        'refund_sandbox_api' => env('NIUBIZ_REFUND_SANDBOX_API', 'https://apitestenv.vnforapps.com'),
        'script'             => env('NIUBIZ_SCRIPT', 'https://static-content.vnforapps.com/v2/js/checkout.js'),
        'sandbox_script'     => env('NIUBIZ_SANDBOX_SCRIPT', 'https://static-content-qas.vnforapps.com/env/sandbox/js/checkout.js'),
    ],

    'izipay' => [
        'api' => env('IZIPAY_API', 'https://api.micuentaweb.pe'),
    ],
];
