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
    // firefly.eventsourcing.store.provider is unset too -> the in-memory event store. The loan tests run it on
    // 'database' (FIREFLY_EVENTSOURCING_STORE=database): events, snapshots and projection checkpoints in four portable
    // tables, with 'publish' => ['enabled' => true] writing each appended event to the outbox in the same transaction.
];
