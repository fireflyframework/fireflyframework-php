<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\BeanListenerInterfaceFixtures;

/**
 * M4 review #6, Important 1: the canonical hexagonal shape — a `#[Bean]` factory method whose
 * declared return type is this interface, never a concrete class. `ContextScanner` never scans an
 * interface, so a lookup keyed on `ListenerPort::class` in the compiled `ContextManifest` always
 * misses; `CacheB` (below), the concrete class this interface's sole `#[Bean]` factory actually
 * returns, is what carries the real, scanned `#[AsEventListener]`/`#[PreDestroy]` entries.
 */
interface ListenerPort {}
