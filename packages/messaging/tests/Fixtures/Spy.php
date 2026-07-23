<?php

declare(strict_types=1);

namespace Firefly\Messaging\Tests\Fixtures;

final class Spy
{
    /** @var list<string> */
    public array $seen = [];

    public function record(string $topic): void
    {
        $this->seen[] = $topic;
    }
}
