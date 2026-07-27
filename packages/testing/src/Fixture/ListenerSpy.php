<?php

declare(strict_types=1);

namespace Firefly\Testing\Fixture;

/** Records values a listener/handler fixture saw (event types, topics, keys). Replaces the per-package Spy. */
final class ListenerSpy
{
    /** @var list<string> */
    public array $seen = [];

    public function record(string $value): void
    {
        $this->seen[] = $value;
    }
}
