<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Support;

use Firefly\Data\DataServiceProvider;
use Firefly\Resilience\ResilienceServiceProvider;
use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\ServiceProvider;

/**
 * ResilienceMethodCapstoneTestCase's sibling, over a real database: the same UNCACHED boot (every AdviceSource
 * collected as a #[Component] through Container::getAll(), the plan built by DataAutoConfiguration, the bean
 * wrapped by the real TransactionalBeanPostProcessor), but with sqlite behind it so a rollback is something
 * rows can be counted for rather than a transcript line.
 *
 * It exists because the advice ORDER is only half of the "a retry opens a transaction of its own" claim. The
 * other half is that each attempt re-enters the chain BELOW the resilience link, and the only place that can
 * be observed is a method carrying both attributes, writing rows, on the real chain. A recording inner link
 * proves the sequence (ResilienceCompositionOrderTest); this proves the transaction was a transaction.
 *
 * `firefly.cache.path` points at a directory holding nothing, for the reason every capstone here gives:
 * every cachedFile() probe must answer null so the SCAN branch is the one under test.
 *
 * `retry.ledger` budgets THREE attempts with no wait: three is the smallest number that distinguishes "the
 * chain was re-entered" from "the cursor ran away by one" — with two attempts a single skipped link and a
 * correct chain can produce the same row count.
 */
abstract class ResilienceTransactionalCapstoneTestCase extends FireflyDatabaseTestCase
{
    /** @return list<class-string<ServiceProvider>> */
    protected function fireflyProviders(): array
    {
        return [DataServiceProvider::class, ResilienceServiceProvider::class];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            'cache.default' => 'array',
            'firefly.scan.paths' => ['Firefly\\Resilience\\Tests\\Fixtures\\TransactionalMethod\\' => dirname(__DIR__).'/Fixtures/TransactionalMethod'],
            'firefly.cache.path' => sys_get_temp_dir().'/firefly-resilience-tx-uncached-'.bin2hex(random_bytes(6)),
            'firefly.resilience.method.enabled' => true,
            'firefly.resilience.retry.ledger' => ['max-attempts' => 3, 'wait-duration' => '0s'],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createSchema('ledger', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('ref');
        });
    }
}
