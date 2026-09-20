<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Fixtures\Capstone;

use Firefly\Container\Attributes\Service;
use Firefly\Data\Transaction\Attributes\Transactional;
use Firefly\Data\Transaction\Propagation;
use Firefly\Data\Transaction\TransactionalDescriptor;
use Firefly\Data\Transaction\TransactionTemplate;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The end-to-end fixture: a #[Service] with class-level #[Transactional]. Every public method is proxied; the
 * class-level default is REQUIRED/rollback-on-Throwable, overridden on logButKeep(). NOT `final` — the proxy
 * extends it. The injected TransactionTemplate drives the NESTED inner unit of work without self-invocation
 * (which would bypass the proxy — a documented Spring limitation).
 */
#[Service]
#[Transactional]
class AccountService
{
    public function __construct(private readonly TransactionTemplate $template) {}

    public function transferAndCommit(): void
    {
        DB::table('accounts')->insert(['name' => 'a']);
        DB::table('accounts')->insert(['name' => 'b']);
    }

    public function transferAndFail(): void
    {
        DB::table('accounts')->insert(['name' => 'a']);
        DB::table('accounts')->insert(['name' => 'b']);

        throw new RuntimeException('boom');
    }

    public function insertDuplicate(): void
    {
        DB::table('accounts')->insert(['id' => 1, 'name' => 'first']);
        DB::table('accounts')->insert(['id' => 1, 'name' => 'second']);
    }

    /** A method-level timeout: the slow statement plus the sleep overrun one second, so the proxy must roll back. */
    #[Transactional(timeout: 1)]
    public function slowTransfer(): void
    {
        DB::table('accounts')->insert(['name' => 'slow']);
        DB::select('with recursive c(x) as (select 1 union all select x + 1 from c where x < 500000) select count(*) as n from c');
        usleep(1_100_000);
    }

    #[Transactional(noRollbackFor: [IgnorableException::class])]
    public function logButKeep(): void
    {
        DB::table('accounts')->insert(['name' => 'kept']);

        throw new IgnorableException('ignored');
    }

    public function outerWithNested(): void
    {
        DB::table('accounts')->insert(['name' => 'outer']);

        try {
            $this->template->execute(function (): void {
                DB::table('accounts')->insert(['name' => 'inner']);

                throw new RuntimeException('inner fail');
            }, new TransactionalDescriptor(propagation: Propagation::NESTED));
        } catch (RuntimeException) {
        }
    }
}
