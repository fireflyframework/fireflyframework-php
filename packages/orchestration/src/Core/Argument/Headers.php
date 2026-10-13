<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Argument;

use Attribute;

/** Injects every execution header as array<string, string>. */
#[Attribute(Attribute::TARGET_PARAMETER)]
final readonly class Headers {}
