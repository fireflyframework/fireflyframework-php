<?php

declare(strict_types=1);

namespace Firefly\Container\Tests\Fixtures;

use Firefly\Container\Attributes\Service;

#[Service]
final class FireSiren implements Siren
{
    public function wail(): string
    {
        return 'nawnaw';
    }
}
