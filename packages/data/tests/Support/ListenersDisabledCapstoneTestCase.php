<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Support;

/** The same boot with firefly.data.transactional-event-listeners.enabled=false: nothing is registered. */
abstract class ListenersDisabledCapstoneTestCase extends ListenersCapstoneTestCase
{
    protected function listenersEnabled(): bool
    {
        return false;
    }
}
