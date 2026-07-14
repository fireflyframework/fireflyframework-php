<?php

declare(strict_types=1);

namespace Firefly\Container;

/**
 * Bean lifetime. Session scope is intentionally deferred to firefly/session.
 */
enum Scope
{
    case Singleton;
    case Transient;
    case Scoped;
}
