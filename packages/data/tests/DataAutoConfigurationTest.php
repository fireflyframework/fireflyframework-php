<?php

declare(strict_types=1);

use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Container\Container as FireflyContainer;
use Firefly\Container\Registrar\ContainerRegistrar;
use Firefly\Container\Scanner\ComponentManifest;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Scan\AppScan;
use Firefly\Data\DataAutoConfiguration;
use Firefly\Data\Domain\AggregateTracker;
use Firefly\Data\Domain\DomainEventDispatcher;
use Firefly\Data\Proxy\Advice;
use Firefly\Data\Proxy\AdviceSource;
use Firefly\Data\Proxy\InterceptorRegistry;
use Firefly\Data\Proxy\ProxyFactory;
use Firefly\Data\Proxy\ProxyPlan;
use Firefly\Data\Proxy\ProxyPlanCompiler;
use Firefly\Data\Proxy\TransactionalAdviceSource;
use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Tests\Fixtures\Chain\AuditAdviceSource;
use Firefly\Data\Tests\Fixtures\Chain\AuditInterceptor;
use Firefly\Data\Tests\Fixtures\Chain\ChainedLedger;
use Firefly\Data\Tests\Fixtures\Transactional\TransferService;
use Firefly\Data\Transaction\TransactionalManifest;
use Firefly\Data\Transaction\TransactionalManifestCompiler;
use Firefly\Data\Transaction\TransactionInterceptor;
use Firefly\Data\Transaction\TransactionTemplate;
use Firefly\Testing\Double\RecordingApplicationEventPublisher;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

it('is an ordered #[Configuration] whose beans back off ConditionalOnMissingBean', function () {
    $class = new ReflectionClass(DataAutoConfiguration::class);

    $manifest = $class->getMethod('transactionalManifest')->getAttributes(ConditionalOnMissingBean::class)[0]->newInstance();
    $plan = $class->getMethod('proxyPlan')->getAttributes(ConditionalOnMissingBean::class)[0]->newInstance();
    $registry = $class->getMethod('interceptorRegistry')->getAttributes(ConditionalOnMissingBean::class)[0]->newInstance();

    expect($class->getAttributes(Configuration::class))->not->toBeEmpty()
        ->and($class->getAttributes(Order::class)[0]->newInstance()->order)->toBe(1000)
        ->and($manifest->type)->toBe(TransactionalManifest::class)
        ->and($plan->type)->toBe(ProxyPlan::class)
        ->and($registry->type)->toBe(InterceptorRegistry::class);
});

it('builds the transaction engine beans incl. the after-commit dispatch graph', function () {
    $config = new DataAutoConfiguration;

    $tracker = $config->aggregateTracker();
    $dispatcher = $config->domainEventDispatcher($tracker, new RecordingApplicationEventPublisher);
    $template = $config->transactionTemplate($dispatcher);

    expect($tracker)->toBeInstanceOf(AggregateTracker::class)
        ->and($dispatcher)->toBeInstanceOf(DomainEventDispatcher::class)
        ->and($template)->toBeInstanceOf(TransactionTemplate::class)
        ->and($config->transactionInterceptor($template))->toBeInstanceOf(TransactionInterceptor::class)
        ->and($config->transactionalManifest(dataConfigContainer()))->toBeInstanceOf(TransactionalManifest::class)
        ->and($config->transactionalManifest(dataConfigContainer())->all())->toBe([])
        ->and($config->proxyFactory())->toBeInstanceOf(ProxyFactory::class);
});

/**
 * A container with no firefly.cache.path and no firefly.scan.paths: the "nothing configured" branch.
 *
 * @param  array<string,mixed>  $firefly
 */
function dataConfigContainer(array $firefly = []): Container
{
    $c = new Container;
    $c->instance('config', new Repository(['firefly' => $firefly]));

    return $c;
}

// transactionalManifest() used to return an unconditional empty manifest, which made #[Transactional] a
// silent no-op: nothing anywhere loaded the compiled transactional.php, so hasProxyFor() was always false and
// TransactionalBeanPostProcessor handed back every bean unwrapped.
it('scans #[Transactional] in-process when firefly.scan.paths is set and nothing is compiled', function () {
    $manifest = (new DataAutoConfiguration)->transactionalManifest(dataConfigContainer([
        // Scoped to Ordering/: the Fixtures root also holds ProxyUnsupported/ByRefService, a deliberate
        // negative fixture whose by-reference parameter the scanner rejects by design.
        'scan' => ['paths' => ['Firefly\\Data\\Tests\\Fixtures\\Ordering\\' => __DIR__.'/Fixtures/Ordering']],
    ]));

    expect($manifest)->toBeInstanceOf(TransactionalManifest::class)
        ->and($manifest->all())->not->toBe([]);
});

/**
 * The Chain fixtures as scan roots: a #[Transactional] class plus a second AdviceSource that claims it.
 *
 * @return array<string, string>
 */
function chainScanPaths(): array
{
    return ['Firefly\\Data\\Tests\\Fixtures\\Chain\\' => __DIR__.'/Fixtures/Chain'];
}

/**
 * The Firefly container facade proxyPlan() collects AdviceSource beans through, with the given sources tagged
 * exactly as ContainerRegistrar tags a #[Component]'s interfaces at FlushDefinitions.
 *
 * @param  list<class-string<AdviceSource>>  $sources
 */
function adviceSourceBeans(Container $container, array $sources): FireflyContainer
{
    if ($sources !== []) {
        $container->tag($sources, (new ContainerRegistrar($container))->tagFor(AdviceSource::class));
    }

    return new FireflyContainer($container, new ComponentManifest([]));
}

function freshCacheDir(): string
{
    $dir = sys_get_temp_dir().'/firefly-data-autoconfig-'.bin2hex(random_bytes(6));
    mkdir($dir, 0o700, true);

    return $dir;
}

it('loads proxy-plan.php when a compiler wrote one, before anything else is consulted', function () {
    $dir = freshCacheDir();
    $written = ProxyPlan::fromTransactionalManifest((new TransactionalScanner)->scan(['Firefly\\Data\\Tests\\Fixtures\\Transactional\\' => __DIR__.'/Fixtures/Transactional']));
    (new ProxyPlanCompiler)->write($written, $dir.'/'.AppScan::PROXY_PLAN);

    // Scan paths ARE set and point somewhere else entirely: the compiled plan wins and they are never walked.
    $container = dataConfigContainer(['cache' => ['path' => $dir], 'scan' => ['paths' => chainScanPaths()]]);
    $plan = (new DataAutoConfiguration)->proxyPlan($container, new TransactionalManifest([], []), adviceSourceBeans($container, [TransactionalAdviceSource::class, AuditAdviceSource::class]));

    expect($plan->toArray())->toBe($written->toArray())
        ->and($plan->hasProxyFor(TransferService::class))->toBeTrue()
        ->and($plan->hasProxyFor(ChainedLedger::class))->toBeFalse();
});

// Today's firefly:cache writes transactional.php (and the proxies for it) but no proxy-plan.php. Such an app used
// to fall through to the scan branch on every boot — ProxyChainBootTest's uncached path, with its reflection and
// its in-process proxy generation — although its compiled proxies were generated for exactly this plan.
it('bridges a transactional-only plan from a compiled transactional.php rather than scanning', function () {
    $dir = freshCacheDir();
    $psr4 = ['Firefly\\Data\\Tests\\Fixtures\\Transactional\\' => __DIR__.'/Fixtures/Transactional'];
    (new TransactionalManifestCompiler)->write((new TransactionalScanner)->scan($psr4), $dir.'/'.AppScan::TRANSACTIONAL);

    $config = new DataAutoConfiguration;
    $container = dataConfigContainer(['cache' => ['path' => $dir], 'scan' => ['paths' => chainScanPaths()]]);
    $transactional = $config->transactionalManifest($container); // the cached branch of the manifest bean
    $plan = $config->proxyPlan($container, $transactional, adviceSourceBeans($container, [TransactionalAdviceSource::class, AuditAdviceSource::class]));

    expect($transactional->hasProxyFor(TransferService::class))->toBeTrue()
        ->and($plan->toArray())->toBe(ProxyPlan::fromTransactionalManifest($transactional)->toArray())
        ->and(array_keys($plan->adviceFor(TransferService::class)))->toBe([Advice::TRANSACTIONAL])
        ->and($plan->hasProxyFor(ChainedLedger::class))->toBeFalse(); // the scan roots were never consulted
});

it('scans firefly.scan.paths through every AdviceSource bean and materialises the proxies when nothing is compiled', function () {
    $container = dataConfigContainer(['cache' => ['path' => freshCacheDir()], 'scan' => ['paths' => chainScanPaths()]]);

    $plan = (new DataAutoConfiguration)->proxyPlan($container, new TransactionalManifest([], []), adviceSourceBeans($container, [AuditAdviceSource::class, TransactionalAdviceSource::class]));

    expect($plan->hasProxyFor(ChainedLedger::class))->toBeTrue()
        ->and(array_keys($plan->adviceFor(ChainedLedger::class)))->toBe(['audit', Advice::TRANSACTIONAL])
        ->and(array_column($plan->methodsFor(ChainedLedger::class)['peek'], 'advice'))->toBe(['audit'])
        ->and(class_exists($plan->proxyClassFor(ChainedLedger::class), false))->toBeTrue();
});

it('derives a transactional-only plan from the bound manifest when there are no scan paths at all', function () {
    $container = dataConfigContainer();
    $transactional = (new TransactionalScanner)->scan(['Firefly\\Data\\Tests\\Fixtures\\Transactional\\' => __DIR__.'/Fixtures/Transactional']);

    $plan = (new DataAutoConfiguration)->proxyPlan($container, $transactional, adviceSourceBeans($container, []));

    expect($plan->toArray())->toBe(ProxyPlan::fromTransactionalManifest($transactional)->toArray())
        ->and($plan->hasProxyFor(TransferService::class))->toBeTrue();
});

it('builds an InterceptorRegistry over the application container', function () {
    $container = dataConfigContainer();
    $audit = new AuditInterceptor;
    $container->instance(AuditInterceptor::class, $audit);

    $registry = (new DataAutoConfiguration)->interceptorRegistry($container);

    expect($registry)->toBeInstanceOf(InterceptorRegistry::class)
        ->and($registry->for(new Advice('audit', AuditInterceptor::class, stdClass::class, 100)))->toBe($audit);
});
