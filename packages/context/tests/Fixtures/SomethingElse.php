<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\Fixtures;

/**
 * A second, unrelated interface used to prove containsType() is NOT satisfied by an interface
 * that happens to be declared on some other definition.
 */
interface SomethingElse {}
