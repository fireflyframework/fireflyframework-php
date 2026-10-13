<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Tests\Fixtures\Events;

enum OrderChannel: string
{
    case Web = 'web';
    case Phone = 'phone';
}
