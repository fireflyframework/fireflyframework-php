<?php

declare(strict_types=1);

namespace Firefly\Data\Proxy;

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Contracts\Container\Container;

/**
 * Resolves the interceptor bean an advice names, at the moment a bean is wrapped (pass 2 of the BPP), so an
 * advice whose capability is switched off — its #[Bean] conditioned away — degrades to a PassThroughInterceptor
 * rather than a BindingResolutionException at boot. A bean that IS bound but is not a MethodInterceptor is a
 * real misconfiguration and fails loud.
 */
final class InterceptorRegistry
{
    public function __construct(private readonly Container $container) {}

    public function for(Advice $advice): MethodInterceptor
    {
        if (! $this->container->bound($advice->interceptorClass)) {
            return new PassThroughInterceptor;
        }

        $interceptor = $this->container->make($advice->interceptorClass);
        if (! $interceptor instanceof MethodInterceptor) {
            throw new ConfigurationException("The [{$advice->id}] advice names {$advice->interceptorClass} as its interceptor, but that bean does not implement ".MethodInterceptor::class.'.');
        }

        return $interceptor;
    }
}
