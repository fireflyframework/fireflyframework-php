<?php

declare(strict_types=1);

namespace Firefly\Domain;

/**
 * Marker for a domain value object: no identity, immutable (a `readonly` class by convention), equal by value.
 * Combine with the ValueObjectEquality trait for structural equality, or implement equals() by hand.
 */
interface ValueObject {}
