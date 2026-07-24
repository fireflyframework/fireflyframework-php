<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Security;

/**
 * The command-side authorization SEAM (interface only in M10). authorize() throws a kernel
 * Firefly\Kernel\Exception\Security\AuthorizationException (403) on deny in the M11-security implementation; the
 * shipped AllowAllAuthorizer default no-ops. Real policy/permission evaluation is M11 — a bean swap.
 */
interface CommandAuthorizer
{
    public function authorize(object $command): void;
}
