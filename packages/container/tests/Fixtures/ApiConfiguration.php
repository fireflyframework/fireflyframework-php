<?php

declare(strict_types=1);

namespace Firefly\Container\Tests\Fixtures;

use Attribute;
use Firefly\Container\Attributes\Configuration;

/**
 * A user-defined stereotype that SPECIALISES #[Configuration], exactly the way
 * #[Configuration] itself specialises #[Component].
 *
 * Its short name is 'apiconfiguration', not 'configuration', which is precisely
 * why ComponentScanner's old `$shortAttr === 'configuration'` string gate
 * dropped every #[Bean] method declared under it.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class ApiConfiguration extends Configuration {}
