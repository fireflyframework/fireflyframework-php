<?php

declare(strict_types=1);

namespace Firefly\Domain;

/**
 * Structural value equality for a ValueObject: same concrete class and equal public/readonly state. Uses
 * get_object_vars($this) (scope-visible properties) — deliberately NOT ReflectionProperty, so firefly/domain
 * stays reflection-free. Suitable for flat readonly value objects; a nested-object VO should compose equality.
 */
trait ValueObjectEquality
{
    public function equals(self $other): bool
    {
        // get_class(), not $this::class: when this trait is flattened into a `final` VO (the norm), PHPStan can
        // statically prove `$this::class === $other::class` always holds and flags it — get_class() is runtime-
        // identical but opaque to that narrowing, so the concrete-type guard stays meaningful for non-final VOs too.
        return get_class($this) === get_class($other)
            && get_object_vars($this) == get_object_vars($other);
    }
}
