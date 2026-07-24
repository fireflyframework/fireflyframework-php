<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Security;

/**
 * The shipped default for BOTH authorization seams: allow everything. One class implements both ports (a message is
 * either a command or a query at a call site), bound to each interface by the auto-config. M11-security replaces it.
 */
final class AllowAllAuthorizer implements CommandAuthorizer, QueryAuthorizer
{
    public function authorize(object $command): void {}
}
