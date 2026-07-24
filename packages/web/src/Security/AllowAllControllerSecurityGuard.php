<?php

declare(strict_types=1);

namespace Firefly\Web\Security;

/** The shipped no-op default: no method security until firefly/security binds a real guard. */
final class AllowAllControllerSecurityGuard implements ControllerSecurityGuard
{
    public function check(string $controllerClass, string $method, array $args): void {}
}
