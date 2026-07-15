<?php

declare(strict_types=1);

namespace Firefly\Context\Lifecycle;

use Attribute;

/**
 * Marks a method to be invoked when a bean is destroyed — Spring's @PreDestroy. Singleton beans
 * run these at context close; Scoped beans run them per request (see DisposableBeanRegistry),
 * always BEFORE Illuminate\Container\Container::forgetScopedInstances(), which has no destruction
 * callback of its own.
 *
 * INERT METADATA ONLY, same rule as M2's attributes and M4's condition attributes: this class
 * carries no invocation logic. InitDestroyInvoker supplies all discovery/dispatch behaviour.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class PreDestroy {}
