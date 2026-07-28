<?php

declare(strict_types=1);

return [
    'scan' => [
        'paths' => [
            'Lumen\\' => __DIR__.'/../src',
        ],
    ],
    // firefly.eda.provider is unset here -> the in-memory EventPublisher (zero external infra).
    // Set 'eda' => ['provider' => 'postgres'] to switch on the genuine same-transaction outbox.
];
