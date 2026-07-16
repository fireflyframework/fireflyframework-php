<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\DedupeKeyFixtures;

final readonly class DedupeEvent
{
    public function __construct(public string $tag) {}
}
