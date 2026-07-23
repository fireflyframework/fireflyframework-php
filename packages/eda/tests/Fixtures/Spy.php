<?php

declare(strict_types=1);

namespace Firefly\Eda\Tests\Fixtures;

final class Spy
{
    /** @var list<string> */
    public array $seen = [];

    public function record(string $eventType): void
    {
        $this->seen[] = $eventType;
    }
}
