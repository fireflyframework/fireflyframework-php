<?php

declare(strict_types=1);

namespace Firefly\Data\Proxy;

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves the interceptor bean an advice names, at the moment a bean is wrapped (pass 2 of the BPP).
 *
 * Three outcomes, and the advice — not this class — decides between the first two. An interceptor that is
 * not bound degrades to a PassThroughInterceptor ONLY when the advice declared itself inert when unbound
 * (Advice::$inertWhenUnbound: Security's advice, whose bean is conditioned away by the master flag on purpose).
 * Every other unbound interceptor is a ConfigurationException naming the advice and the bean, because a
 * compiled plan that promises advice nothing can run is a misconfiguration, and letting the method run
 * unadvised would be the same silent fail-open this framework has already had to fix once for method security.
 * A bean that IS bound but is not a MethodInterceptor is a misconfiguration whichever way the advice leans.
 */
final class InterceptorRegistry
{
    public function __construct(private readonly Container $container) {}

    public function for(Advice $advice): MethodInterceptor
    {
        if (! $this->container->bound($advice->interceptorClass)) {
            if ($advice->inertWhenUnbound) {
                return new PassThroughInterceptor;
            }

            throw new ConfigurationException(
                "The [{$advice->id}] advice names {$advice->interceptorClass} as its interceptor, but no such bean is bound. "
                .'Bind it (or enable the capability whose #[Bean] provides it), or declare the advice inert when unbound if running the method unadvised is acceptable.'
            );
        }

        $interceptor = $this->container->make($advice->interceptorClass);
        if (! $interceptor instanceof MethodInterceptor) {
            throw new ConfigurationException("The [{$advice->id}] advice names {$advice->interceptorClass} as its interceptor, but that bean does not implement ".MethodInterceptor::class.'.');
        }

        return $interceptor;
    }
}
