<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\InvalidFixtures\UnbuildableType;

use JsonSerializable;

/** A value object PayloadNormalizer can write (through jsonSerialize()) but the convention mapper cannot rebuild. */
final readonly class Money implements JsonSerializable
{
    public function __construct(public int $cents) {}

    /** @return array{cents: int} */
    public function jsonSerialize(): array
    {
        return ['cents' => $this->cents];
    }
}
