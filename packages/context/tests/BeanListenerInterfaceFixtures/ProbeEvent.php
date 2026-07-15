<?php

declare(strict_types=1);

namespace Firefly\Context\Tests\BeanListenerInterfaceFixtures;

final readonly class ProbeEvent
{
    public function __construct(public string $tag) {}
}
