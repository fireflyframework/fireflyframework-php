<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Settings;

/** firefly.orchestration.dlq.* — `store` is `memory` or `database`. */
final readonly class DlqSettings
{
    public function __construct(
        public bool $enabled,
        public string $store,
    ) {}
}
