<?php

declare(strict_types=1);

namespace Firefly\Security\Cqrs;

use Firefly\Cqrs\Handler\HandlerKind;
use Firefly\Cqrs\Security\QueryAuthorizer;

/** The real query-side authorizer — twin of SecurityCommandAuthorizer. */
final class SecurityQueryAuthorizer implements QueryAuthorizer
{
    public function __construct(private readonly MethodSecurityMessageEnforcer $enforcer) {}

    public function authorize(object $query): void
    {
        $this->enforcer->enforce($query, HandlerKind::Query);
    }
}
