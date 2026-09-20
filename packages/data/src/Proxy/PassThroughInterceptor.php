<?php

declare(strict_types=1);

namespace Firefly\Data\Proxy;

/**
 * The link a proxy runs in place of an advice whose interceptor bean is not bound — a class compiled with
 * security advice booting in an application that has not enabled security. It does nothing but proceed, and
 * it exists so the proxy's typed property is always set: a proxy whose method-security rules are inert is the
 * documented "annotations do nothing until firefly.security.enabled" behaviour, not an error.
 */
final class PassThroughInterceptor implements MethodInterceptor
{
    public function invoke(MethodInvocation $invocation): mixed
    {
        return $invocation->proceed();
    }
}
