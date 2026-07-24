<?php

declare(strict_types=1);

namespace Firefly\Security\Cqrs;

use Firefly\Cqrs\Handler\HandlerKind;
use Firefly\Cqrs\Security\CommandAuthorizer;

/** The real command-side authorizer — bound ahead of AllowAllAuthorizer so it wins. */
final class SecurityCommandAuthorizer implements CommandAuthorizer
{
    public function __construct(private readonly MethodSecurityMessageEnforcer $enforcer) {}

    public function authorize(object $command): void
    {
        $this->enforcer->enforce($command, HandlerKind::Command);
    }
}
