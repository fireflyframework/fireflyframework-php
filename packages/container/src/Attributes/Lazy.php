<?php

declare(strict_types=1);

namespace Firefly\Container\Attributes;

use Attribute;

/**
 * Marks a #[Component] class as lazily resolved: firefly/context's EagerSingletonsPass skips it
 * during eager Scope::Singleton resolution at boot, so it is only built the first time something
 * actually asks the container for it. Does NOT change ContainerRegistrar's binding behaviour —
 * Illuminate resolves everything lazily by default; this attribute only gates the boot-time EAGER
 * resolution pass.
 *
 * On a #[Component] CLASS: captured by ComponentScanner onto ComponentDescriptor::$lazy, so
 * EagerSingletonsPass reads it from the compiled manifest — zero reflection at boot.
 *
 * On a #[Bean] factory METHOD: captured by ComponentScanner onto BeanDescriptor::$lazy, so
 * EagerSingletonsPass reads it from the compiled manifest too — zero reflection at boot either way.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class Lazy {}
