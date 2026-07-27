<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Fixtures\App;

final readonly class DemoQuery
{
    public function __construct(public string $id) {}
}
