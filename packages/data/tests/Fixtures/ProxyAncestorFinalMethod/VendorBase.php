<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\ProxyAncestorFinalMethod;

/**
 * A base an application author does not own — the framework's own `AutoConfiguration::register()` is `final`
 * exactly like this. Nothing here carries #[Transactional]; the plan reaches `register()` only because the
 * class-level attribute on the leaf below plans every public method the leaf EXPOSES.
 */
abstract class VendorBase
{
    final public function register(): void {}

    public function inheritedStep(): void {}
}
