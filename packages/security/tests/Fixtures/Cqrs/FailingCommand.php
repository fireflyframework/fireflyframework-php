<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\Cqrs;

/** A command whose handler fails the way the `failure` names; see FailingCommandHandler. */
final class FailingCommand
{
    public function __construct(public string $failure) {}
}
