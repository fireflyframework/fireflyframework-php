<?php

declare(strict_types=1);

use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Data\DataAutoConfiguration;
use Firefly\Data\Domain\AggregateTracker;
use Firefly\Data\Domain\DomainEventDispatcher;
use Firefly\Data\Proxy\ProxyFactory;
use Firefly\Data\Transaction\TransactionalManifest;
use Firefly\Data\Transaction\TransactionInterceptor;
use Firefly\Data\Transaction\TransactionTemplate;
use Firefly\Testing\Double\RecordingApplicationEventPublisher;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

it('is an ordered #[Configuration] whose beans back off ConditionalOnMissingBean', function () {
    $class = new ReflectionClass(DataAutoConfiguration::class);

    $manifest = $class->getMethod('transactionalManifest')->getAttributes(ConditionalOnMissingBean::class)[0]->newInstance();

    expect($class->getAttributes(Configuration::class))->not->toBeEmpty()
        ->and($class->getAttributes(Order::class)[0]->newInstance()->order)->toBe(1000)
        ->and($manifest->type)->toBe(TransactionalManifest::class);
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
