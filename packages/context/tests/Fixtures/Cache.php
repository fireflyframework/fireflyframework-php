<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\Fixtures;

/**
 * A stand-in interface used across Definition/Condition tests to exercise
 * BeanDefinitionRegistry::containsType()'s "declared interface" and "#[Bean] return type" routes
 * with a real class-string (PHPStan requires ComponentDescriptor::$interfaces to be
 * list<class-string>, so an arbitrary made-up string literal will not satisfy it).
 */
interface Cache {}
