<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\BeanListenerInterfaceFixtures;

/**
 * M4 review #7, Minor 1: a SEPARATE interface from `ListenerPort` (never bound by the same `#[Bean]`
 * abstract) so the `Scope::Transient` dedupe arm below cannot collide with ARM B's
 * `Scope::Singleton` binding of `ListenerPort`. `ContextScanner` never scans an interface — see
 * `ListenerPort`'s own docblock for why that is exactly the shape the late-bound listener recovery
 * path exists for.
 */
interface TransientListenerPort {}
