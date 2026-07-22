<?php

declare(strict_types=1);

namespace Firefly\Domain;

/**
 * A DDD entity: identity, not value, defines equality. Two entities of the SAME concrete class with equal
 * non-null ids are equal; a transient entity (a null id, not yet persisted) is equal only to itself (object
 * identity). PHP has no runtime generics, so the id is int|string|null (auto-increment or uuid); a subclass may
 * narrow id()'s return type covariantly. Pure PHP — no framework, no reflection.
 */
abstract class Entity
{
    public function __construct(protected int|string|null $id = null) {}

    public function id(): int|string|null
    {
        return $this->id;
    }

    public function isTransient(): bool
    {
        return $this->id === null;
    }

    public function equals(self $other): bool
    {
        if ($this === $other) {
            return true;
        }

        if ($this::class !== $other::class) {
            return false;
        }

        if ($this->isTransient() || $other->isTransient()) {
            return false;
        }

        return $this->id === $other->id;
    }
}
