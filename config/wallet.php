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
        'withdrawal_clearing' => 'withdrawal_clearing',
        'withdrawal_payout' => 'withdrawal_payout',
    ],

    'gateway' => [
        'secret' => env('WALLET_GATEWAY_SECRET', ''),
        'default' => env('WALLET_GATEWAY', 'simulated'),
    ],

    'deposit' => [
        'reconcile_after_minutes' => (int) env('WALLET_DEPOSIT_RECONCILE_AFTER_MINUTES', 15),
        'reconcile_batch_size' => (int) env('WALLET_DEPOSIT_RECONCILE_BATCH_SIZE', 100),
    ],

    'rate_limits' => [
        'writes' => (int) env('WALLET_RATE_LIMIT_WRITES', 30),
        'reads' => (int) env('WALLET_RATE_LIMIT_READS', 120),
        'callbacks' => (int) env('WALLET_RATE_LIMIT_CALLBACKS', 120),
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
