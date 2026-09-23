<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\InheritedFinalAttribute;

use Firefly\Container\Attributes\Service;

/** The bean whose proxy would fatal at `require` with "Cannot override final method" if this compiled. */
#[Service]
class SealedChildService extends SealedBase {}
