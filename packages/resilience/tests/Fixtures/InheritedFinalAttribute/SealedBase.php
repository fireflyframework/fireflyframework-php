<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\InheritedFinalAttribute;

use Firefly\Resilience\Method\Retry;

/**
 * The other half of the final-method rule: somebody DID write the attribute on the final method. That the
 * class reached through inheritance is the child changes nothing — the attribute and the `final` are in the
 * same file, in the author's own hands, so the refusal's remedy is one they can follow. Abstract, so the scan
 * reaches this method only through the child.
 */
abstract class SealedBase
{
    #[Retry('payments')]
    final public function sealed(): void {}
}
