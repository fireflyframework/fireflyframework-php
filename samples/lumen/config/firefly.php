<?php

declare(strict_types=1);

return [
    'scan' => [
        'paths' => [
            'Lumen\\' => __DIR__.'/../src',
        ],
    ],
    // firefly.eda.provider is unset here -> the in-memory EventPublisher (zero external infra).
    // Set 'eda' => ['provider' => 'database'] for the portable same-transaction outbox on any database (deliver it
    // with `php artisan firefly:eda:consume`), or 'postgres' for the same outbox plus LISTEN/NOTIFY wake-ups.
];
