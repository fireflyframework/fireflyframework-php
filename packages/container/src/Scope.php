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

    public static function fromName(string $name): self
    {
        return match ($name) {
            'Singleton' => self::Singleton,
            'Transient' => self::Transient,
            'Scoped' => self::Scoped,
            default => throw new \ValueError("Unknown Scope case: {$name}"),
        };
    }
}
