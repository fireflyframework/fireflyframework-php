<?php

declare(strict_types=1);

namespace Firefly\Callbacks\Tests\Support;

/**
 * The callbacks capstone with overlapping, partly duplicated firefly.callbacks.event-types — `*`, `order.*` twice
 * and `order.created` all match `order.created` — so a test can prove one envelope still reaches the dispatcher once.
 */
abstract class OverlappingPatternsCapstoneTestCase extends CallbacksCapstoneTestCase
{
    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return array_replace(parent::configOverrides(), [
            'firefly.callbacks.event-types' => ['order.*', 'order.created', '*', 'order.*'],
        ]);
    }
}
