<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Settings;

/** firefly.orchestration.rest.* — off by default; `path` always starts with a slash. */
final readonly class RestSettings
{
    public function __construct(
        public bool $enabled,
        public string $path,
    ) {}
}
