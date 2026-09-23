<?php

declare(strict_types=1);

namespace Firefly\Data\Proxy;

/**
 * One link of advice around a proxied bean method — the AOP Alliance MethodInterceptor that Spring's
 * TransactionInterceptor and AuthorizationManagerBeforeMethodInterceptor both implement, ported.
 *
 * A generated proxy override builds a MethodInvocation over the ORDERED interceptors its method was compiled
 * with and calls proceed(); each interceptor does its work before and/or after calling $invocation->proceed()
 * itself, and the last proceed() reaches the real method through parent::. An interceptor that never calls
 * proceed() has refused the call (a security refusal throws), one that calls it inside try/finally wraps it
 * (a transaction), one that calls setArguments() first narrows what the method receives (a pre-filter). The
 * invocation is single-use: proceed() advances a cursor, so a second call from the same link reaches the NEXT
 * link, never this one again — the same contract as Spring's ReflectiveMethodInvocation.
 *
 * A REPEATING link — one that must run the rest of the chain more than once, which in this framework means a
 * retry and nothing else — therefore does NOT call proceed() twice. It takes `$invocation->invocableClone()`
 * per repetition and proceeds on that, so every repetition walks the same remainder: the transaction, the
 * inner advice and the real method. Calling proceed() twice instead silently drops one inner link per extra
 * call, which for `#[Retry]` beside `#[Transactional]` means attempts after the first run untransacted. The
 * clone is the one sanctioned way around the single-use rule; see MethodInvocation::invocableClone().
 */
interface MethodInterceptor
{
    public function invoke(MethodInvocation $invocation): mixed;
}
