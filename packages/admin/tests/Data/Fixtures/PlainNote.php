<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Data\Fixtures;

/**
 * A plain entity — no Eloquent, no framework base class — whose fields exist ONLY as promoted constructor
 * parameters, and whose identifier is promoted PROTECTED. That is the shape `Firefly\Domain\Entity` gives
 * every DDD aggregate in the framework, and a column scan restricted to public properties would find `title`,
 * `body` and `pinned` while silently losing the key the detail view and delete both address rows by.
 */
final class PlainNote
{
    public function __construct(
        protected int $id,
        public string $title,
        public ?string $body = null,
        public bool $pinned = false,
    ) {}

    public function id(): int
    {
        return $this->id;
    }
}
