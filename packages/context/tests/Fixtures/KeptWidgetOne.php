<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\Fixtures;

/**
 * A concrete FlushDefinitionsPass fixture: survives condition filtering and implements Cache so
 * ContainerRegistrar tags it for getAll(Cache::class). Higher #[Order] value than KeptWidgetTwo,
 * used to prove the flushed facade sorts getAll() ascending by order from the FILTERED manifest.
 */
final class KeptWidgetOne implements Cache {}
