<?php

declare(strict_types=1);

namespace Firefly\Web\Security;

use Firefly\Kernel\Exception\Security\SecurityException;

/**
 * The dispatch-time method-security enforcement port (the twin of the CQRS Command/QueryAuthorizer seam, adding
 * NO proxy). ControllerDispatcher calls check() after argument resolution and immediately before invoking the
 * controller method, passing the resolved positional args so a #param expression can bind them. The shipped
 * default (AllowAllControllerSecurityGuard) is a no-op; firefly/security binds a real implementation that
 * consults the compiled method-security manifest + the no-eval evaluator. Throw a kernel security exception to
 * deny; return void to allow.
 */
interface ControllerSecurityGuard
{
    /**
     * @param  array<int,mixed>  $args  the resolved controller-method arguments (positional)
     *
     * @throws SecurityException
     */
    public function check(string $controllerClass, string $method, array $args): void;
}
