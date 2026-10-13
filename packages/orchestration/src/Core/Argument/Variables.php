<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Argument;

use Attribute;

/** Injects every execution variable as array<string, mixed>. */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Variables {}
