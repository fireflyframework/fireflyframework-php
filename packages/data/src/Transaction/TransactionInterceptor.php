<?php

declare(strict_types=1);

namespace Firefly\Data\Transaction;

use Closure;
use Firefly\Data\Proxy\MethodInterceptor;
use Firefly\Data\Proxy\MethodInvocation;

/**
 * The thin advice the generated proxy calls: run() wraps a `parent::method(...)` closure in the transaction
 * semantics by delegating to the TransactionTemplate (the single source of truth). Held by the proxy in a
 * private $__fireflyTxInterceptor property set by the ProxyFactory.
 *
 * As a MethodInterceptor it is one link of the generated proxy's chain: invoke() reads the TransactionalDescriptor
 * baked for the method off the invocation and hands `fn () => $invocation->proceed()` to run(), so a subclass
 * that overrides run() (the recording spy in ProxyInterceptorRoutingTest, a future decorator) still sees every
 * transactional call exactly as before. A missing descriptor is the REQUIRED default, as it has always been.
 *
 * NOT `final`: it is designed to be extended (a recording spy proves the generated override actually ROUTES
 * through run() rather than calling parent:: directly; future advice composition may decorate it). The concrete
 * type is what ProxyFactory::wrap() accepts for the transactional link, so any stand-in must be a subclass.
 */
class TransactionInterceptor implements MethodInterceptor
{
    public function __construct(private readonly TransactionTemplate $template) {}

    /**
     * @template T
     *
     * @param  Closure(): T  $proceed
     * @return T
     */
    public function run(Closure $proceed, TransactionalDescriptor $descriptor): mixed
    {
        return $this->template->execute($proceed, $descriptor);
    }

    public function invoke(MethodInvocation $invocation): mixed
    {
        $descriptor = $invocation->descriptor(TransactionalDescriptor::class) ?? new TransactionalDescriptor;

        return $this->run(static fn (): mixed => $invocation->proceed(), $descriptor);
    }
}
