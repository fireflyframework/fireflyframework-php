<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\Fixtures;

/**
 * A second concrete FlushDefinitionsPass fixture, also implementing Cache but with a LOWER
 * #[Order] value than KeptWidgetOne, so getAll(Cache::class) must return this one first.
 */
final class KeptWidgetTwo implements Cache {}
