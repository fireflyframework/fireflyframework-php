<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Fixtures\AncestorFinalMethod;

/**
 * A base an application author does not own — the framework's own AutoConfiguration is one, and its
 * `register()` is `final`. Nothing here carries a resilience attribute; everything below arrives by the
 * child's class-level fan-out.
 */
abstract class VendorBase
{
    final public function boot(): void {}

    public function inheritedStep(): void {}
}
