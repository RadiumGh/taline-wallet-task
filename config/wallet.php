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
        'default' => env('WALLET_GATEWAY', 'simulated'),
    ],

    'idempotency' => [
        'ttl_minutes' => (int) env('WALLET_IDEMPOTENCY_TTL_MINUTES', 1440),
    ],

    'outbox' => [
        'batch_size' => (int) env('WALLET_OUTBOX_BATCH_SIZE', 100),
        'max_attempts' => (int) env('WALLET_OUTBOX_MAX_ATTEMPTS', 5),
        'backoff_base_seconds' => (int) env('WALLET_OUTBOX_BACKOFF_BASE_SECONDS', 5),
    ],
];
