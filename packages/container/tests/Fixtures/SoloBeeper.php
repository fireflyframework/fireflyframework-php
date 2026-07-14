<?php

declare(strict_types=1);

namespace Firefly\Container\Tests\Fixtures;

use Firefly\Container\Attributes\Service;

#[Service]
final class SoloBeeper implements Beeper
{
    public function beep(): string
    {
        return 'beep';
    }
}
