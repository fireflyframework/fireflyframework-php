<?php

declare(strict_types=1);

namespace Firefly\Data\Transaction;

use Closure;

/**
 * The thin advice the generated proxy calls: run() wraps a `parent::method(...)` closure in the transaction
 * semantics by delegating to the TransactionTemplate (the single source of truth). Held by the proxy in a
 * private $__fireflyTxInterceptor property set by the ProxyFactory.
 */
final class TransactionInterceptor
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
}
