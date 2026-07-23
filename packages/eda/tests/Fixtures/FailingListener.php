<?php

declare(strict_types=1);

namespace Firefly\Eda\Tests\Fixtures;

use Firefly\Container\Attributes\Component;
use Firefly\Eda\Attributes\EventListener;
use Firefly\Eda\EventEnvelope;
use RuntimeException;

#[Component]
final class FailingListener
{
    #[EventListener('fail.*')]
    public function onFail(EventEnvelope $envelope): void
    {
        throw new RuntimeException('listener boom');
    }
}
