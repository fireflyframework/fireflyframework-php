<?php

declare(strict_types=1);

return [
    'scan' => [
        'paths' => [
            'App\\' => app_path(),
        ],
    ],
    'cache' => [
        'path' => base_path('bootstrap/cache/firefly'),
        'component_manifest' => base_path('bootstrap/cache/firefly/component.php'),
        'context_manifest' => base_path('bootstrap/cache/firefly/context.php'),
    ],
];
