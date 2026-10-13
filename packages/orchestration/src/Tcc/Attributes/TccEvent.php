<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tcc\Attributes;

use Attribute;

/** Publishes an event through firefly/eda when this participant CONFIRMS (jafly @TccEvent); `key` names an input key. */
#[Attribute(Attribute::TARGET_CLASS)]
final readonly class TccEvent
{
    public function __construct(
        public string $topic,
        public string $eventType,
        public string $key = '',
    ) {}
}
