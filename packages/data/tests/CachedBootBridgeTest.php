<?php

declare(strict_types=1);

use Firefly\Context\Scan\AppScan;
use Firefly\Data\Proxy\Advice;
use Firefly\Data\Proxy\ProxyPlan;
use Firefly\Data\Tests\Fixtures\Cached\CachedAuditAdviceSource;
use Firefly\Data\Tests\Fixtures\Cached\CachedLedger;
use Firefly\Data\Tests\Support\CachedBootTestCase;
use Firefly\Data\Transaction\TransactionalManifest;

uses(CachedBootTestCase::class);

/*
 | A cached application must never scan. Before the transactional.php bridge in DataAutoConfiguration::proxyPlan(),
 | a boot with firefly.scan.paths set and a cache written by a firefly:cache from before proxy-plan.php existed fell
 | through to the scan branch on every request — reflecting over the app and generating proxies into a temp directory
 | in what had been a zero-reflection boot. Two discriminators pin the cached branch: the plan lists the transactional
 | advice ALONE although a scan would have collected CachedAuditAdviceSource (a registered #[Component]) and added
 | 'audit', and the proxy class the bean resolves to was loaded from the cache directory's classmap, not written
 | anywhere else.
 */
it('bridges a transactional-only plan from the compiled transactional.php instead of scanning', function () {
    /** @var CachedBootTestCase $this */
    $context = $this->fireflyContext();
    $dir = CachedBootTestCase::compiled();

    expect(AppScan::paths($this->app()))->toBe(CachedBootTestCase::psr4())
        ->and(AppScan::cachedFile($this->app(), AppScan::PROXY_PLAN))->toBeNull()
        ->and(AppScan::cachedFile($this->app(), AppScan::TRANSACTIONAL))->toBe($dir.'/'.AppScan::TRANSACTIONAL)
        ->and($context->has(CachedAuditAdviceSource::class))->toBeTrue(); // the scan branch WOULD have found it

    /** @var ProxyPlan $plan */
    $plan = $context->get(ProxyPlan::class);
    /** @var TransactionalManifest $transactional */
    $transactional = $context->get(TransactionalManifest::class);

    expect($plan)->toBeInstanceOf(ProxyPlan::class)
        ->and(array_keys($plan->adviceFor(CachedLedger::class)))->toBe([Advice::TRANSACTIONAL])
        ->and($plan->toArray())->toBe(ProxyPlan::fromTransactionalManifest($transactional)->toArray());
});

it('resolves the compiled proxy from the cache classmap and runs its transaction', function () {
    /** @var CachedBootTestCase $this */
    $context = $this->fireflyContext();

    /** @var CachedLedger $ledger */
    $ledger = $context->get(CachedLedger::class);

    // realpath() on both sides: sys_get_temp_dir() is a symlink on macOS (/var -> /private/var) and PHP reports
    // a required file by its resolved path.
    expect($ledger::class)->toBe(CachedLedger::class.ProxyPlan::PROXY_SUFFIX)
        ->and((string) realpath((string) (new ReflectionClass($ledger))->getFileName()))->toStartWith((string) realpath(CachedBootTestCase::compiled()).'/proxies/')
        ->and($ledger->post('rent'))->toBe('posted:rent')
        ->and($ledger->peek())->toBe('peek:1');
});
