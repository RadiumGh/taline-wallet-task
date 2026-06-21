<?php

declare(strict_types=1);

return [
    'base_currency' => 'IRR',

    'currencies' => [
        'IRR' => ['scale' => 0],
        'USD' => ['scale' => 2],
        'BTC' => ['scale' => 8],
    ],

    'system_accounts' => [
        'gateway_clearing' => 'gateway_clearing',
    ],

    'gateway' => [
        'secret' => env('WALLET_GATEWAY_SECRET', ''),
    ],

    'idempotency' => [
        'ttl_minutes' => (int) env('WALLET_IDEMPOTENCY_TTL_MINUTES', 1440),
    ],
];
