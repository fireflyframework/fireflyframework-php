<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Argument;

use Attribute;

/** Refuses the call when the parameter resolved to null, naming the method (jafly @Required). */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Required {}
