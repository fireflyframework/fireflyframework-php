<?php

declare(strict_types=1);

namespace Firefly\Data\Proxy;

/**
 * The link a proxy runs in place of an advice that declared itself inert when unbound and whose interceptor
 * bean is indeed absent — a class compiled with security advice booting in an application that has not
 * enabled security. It does nothing but proceed, and it exists so the proxy's typed property is always set:
 * a proxy whose method-security rules are inert is the documented "annotations do nothing until
 * firefly.security.enabled" behaviour, not an error. An advice that did NOT opt in never gets this link; the
 * InterceptorRegistry fails loud for it instead.
 */
final class PassThroughInterceptor implements MethodInterceptor
{
    public function invoke(MethodInvocation $invocation): mixed
    {
        return $invocation->proceed();
    }
}
