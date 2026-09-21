<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Chain;

use Closure;
use Firefly\Data\Transaction\TransactionalDescriptor;
use Firefly\Data\Transaction\TransactionInterceptor;
use Illuminate\Support\Facades\DB;

/**
 * The real TransactionInterceptor with a counter and a probe: run() still opens the real transaction through
 * the template, and records the connection's transaction level as seen by the real method — 1 proves the
 * method body ran INSIDE a transaction this link opened, not merely after it was called.
 */
final class RecordingTransactionInterceptor extends TransactionInterceptor
{
    public int $calls = 0;

    /** @var list<int> the transaction level observed inside each advised call */
    public array $levels = [];

    public function run(Closure $proceed, TransactionalDescriptor $descriptor): mixed
    {
        $this->calls++;

        return parent::run(function () use ($proceed): mixed {
            $this->levels[] = DB::connection()->transactionLevel();

            return $proceed();
        }, $descriptor);
    }
}
