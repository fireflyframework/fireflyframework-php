<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\LateBoundListenerBootEventFixtures;

/**
 * ARM INTERFACE's declared `#[Bean]` return type — "the canonical hexagonal shape". `ContextScanner`
 * never scans an interface, so `RegisterEventListenersPass`'s boot-time sweep structurally cannot
 * find `InterfaceBootListener`'s `#[AsEventListener]` under this key; it is only ever recovered by
 * `RegisterBeanPostProcessorsPass`'s late-bound path, at the bean's first resolution.
 */
interface BootListenerPort {}
