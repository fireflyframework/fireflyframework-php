<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Argument;

use Attribute;

/** Injects the execution's correlation id. */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class CorrelationId {}
