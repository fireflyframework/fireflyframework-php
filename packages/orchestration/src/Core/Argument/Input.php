<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Argument;

use Attribute;

/** Injects the step's input — all of it, or one key of an array input: `#[Input('amount')] int $amount`. */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Input
{
    public function __construct(public string $key = '') {}
}
