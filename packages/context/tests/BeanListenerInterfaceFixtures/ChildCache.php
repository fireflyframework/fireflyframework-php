<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\BeanListenerInterfaceFixtures;

/**
 * ARM C: the concrete class actually constructed by `ConfigC::makeC()`. Declares no
 * `#[AsEventListener]` of its own — it inherits `onProbe()`, attribute included, from `ParentCache`
 * via PHP's ordinary method inheritance, which is also what `ReflectionClass::getMethods()` returns
 * at scan time. See `ParentCache`'s docblock for why this shape regressed M4 review #6's fix.
 */
final class ChildCache extends ParentCache {}
