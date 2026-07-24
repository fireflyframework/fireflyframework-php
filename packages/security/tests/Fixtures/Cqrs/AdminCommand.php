<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\Cqrs;

final class AdminCommand
{
    public function __construct(public string $note = 'x') {}
}
