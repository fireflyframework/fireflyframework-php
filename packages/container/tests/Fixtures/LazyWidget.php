<?php

declare(strict_types=1);

namespace Firefly\Container\Tests\Fixtures;

use Firefly\Container\Attributes\Lazy;
use Firefly\Container\Attributes\Service;

/**
 * A #[Lazy] component: ComponentScanner must capture this onto
 * ComponentDescriptor::$lazy so EagerSingletonsPass can skip it without reflecting.
 */
#[Service]
#[Lazy]
final class LazyWidget {}
