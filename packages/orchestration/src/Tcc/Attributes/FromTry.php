<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tcc\Attributes;

use Attribute;

/** Injects a try result: this participant's own (no argument) or another participant's (jafly @FromTry). */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class FromTry
{
    public function __construct(public string $participantId = '') {}
}
