<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\LateBoundListenerScopeMatrixFixtures;

use Firefly\Container\Attributes\Component;

/**
 * A shared, container-managed singleton every fixture in this directory injects to record what
 * actually fired.
 */
#[Component]
final class MatrixRecorder
{
    /** @var list<string> */
    public array $events = [];

    public function record(string $event): void
    {
        $this->events[] = $event;
    }
}
