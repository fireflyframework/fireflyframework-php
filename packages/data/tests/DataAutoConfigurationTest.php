<?php

declare(strict_types=1);

use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Data\DataAutoConfiguration;
use Firefly\Data\Domain\AggregateTracker;
use Firefly\Data\Domain\DomainEventDispatcher;
use Firefly\Data\Proxy\ProxyFactory;
use Firefly\Data\Tests\Support\SpyEventPublisher;
use Firefly\Data\Transaction\TransactionalManifest;
use Firefly\Data\Transaction\TransactionInterceptor;
use Firefly\Data\Transaction\TransactionTemplate;

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
    $dispatcher = $config->domainEventDispatcher($tracker, new SpyEventPublisher);
    $template = $config->transactionTemplate($dispatcher);

    expect($tracker)->toBeInstanceOf(AggregateTracker::class)
        ->and($dispatcher)->toBeInstanceOf(DomainEventDispatcher::class)
        ->and($template)->toBeInstanceOf(TransactionTemplate::class)
        ->and($config->transactionInterceptor($template))->toBeInstanceOf(TransactionInterceptor::class)
        ->and($config->transactionalManifest())->toBeInstanceOf(TransactionalManifest::class)
        ->and($config->transactionalManifest()->all())->toBe([])
        ->and($config->proxyFactory())->toBeInstanceOf(ProxyFactory::class);
});
