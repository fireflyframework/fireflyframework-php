<?php

declare(strict_types=1);

namespace Firefly\Context\Lifecycle;

use Attribute;

/**
 * Marks a method to be invoked once, after a bean's BeanPostProcessor::beforeInitialization()
 * pass and before its afterInitialization() pass (see BeanPostProcessorChain) — the analog of
 * Spring's PostConstruct annotation.
 *
 * INERT METADATA ONLY, same rule as M2's attributes and M4's condition attributes: this class
 * carries no invocation logic. InitDestroyInvoker supplies all discovery/dispatch behaviour.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class PostConstruct {}
