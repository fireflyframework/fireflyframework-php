<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\Support;

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Cqrs\Command\CommandBus;
use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Cqrs\Query\QueryBus;
use Firefly\Cqrs\Scanner\HandlerScanner;
use Firefly\Cqrs\Tests\CapstoneFixtures\Ledger\HandlerCalls;
use Firefly\Cqrs\Tests\CapstoneFixtures\Ledger\LedgerCqrsConfiguration;
use Firefly\Cqrs\Tests\CapstoneFixtures\Ledger\LedgerTotals;
use Firefly\Data\DataServiceProvider;
use Firefly\Data\Proxy\ProxyClassGenerator;
use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Transaction\TransactionalManifestCompiler;
use Firefly\Eda\Bus\InMemoryEventBus;
use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\EventPublisher;
use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * The full CQRS read/write path with the query cache ON: firefly/data (the #[Transactional] proxy + after-commit
 * domain-event dispatch) + firefly/cqrs (buses, bridge, cache, invalidation) + the Ledger fixtures, over Testbench
 * and sqlite :memory:, on the array cache store. The event bus is the REAL in-memory EventPublisher, bound before
 * boot, so the domain bridge publishes onto it and the invalidator's subscriptions receive what it publishes —
 * the same path another process's envelope takes. Proxies, the transactional manifest and the handler manifest
 * are compiled inline, as firefly:cache would (the CqrsCapstoneTestCase shape).
 */
abstract class CqrsCacheCapstoneTestCase extends FireflyDatabaseTestCase
{
    public InMemoryEventBus $events;

    /** @return list<class-string> */
    protected function fireflyProviders(): array
    {
        return [
            DataServiceProvider::class,
            CqrsServiceProvider::class,
            CqrsWiringProvider::class,
            LedgerComponentsProvider::class,
        ];
    }

    protected function cacheProvider(): string
    {
        return 'cache';
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            'cache.default' => 'array',
            'firefly.cqrs.query.cache.provider' => $this->cacheProvider(),
            'firefly.cqrs.query.cache.invalidation' => ['ledger.closed' => [LedgerTotals::class]],
        ];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        $this->events = new InMemoryEventBus(new SubscriberRegistry);
        $app->instance(EventPublisher::class, $this->events);

        $psr4 = ['Firefly\\Cqrs\\Tests\\CapstoneFixtures\\Ledger\\' => dirname(__DIR__).'/CapstoneFixtures/Ledger'];

        $scanner = new TransactionalScanner;
        $generator = new ProxyClassGenerator;
        foreach ($scanner->scanProxyMethods($psr4) as $target => $methods) {
            $generator->load($target, $methods);
        }
        (new TransactionalManifestCompiler)->write($scanner->scan($psr4), LedgerCqrsConfiguration::manifestPath());

        $handlers = (new HandlerScanner)->scan($psr4);
        $app->instance(HandlerManifest::class, new HandlerManifest(
            $handlers['handlers'],
            $handlers['destinations'],
            $handlers['cachedQueries'],
            $handlers['invalidations'],
        ));
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('ledger_entries', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('account');
            $table->integer('amount');
        });

        HandlerCalls::reset();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function commandBus(): CommandBus
    {
        /** @var CommandBus $bus */
        $bus = $this->app()->make(ApplicationContext::class)->get(CommandBus::class);

        return $bus;
    }

    public function queryBus(): QueryBus
    {
        /** @var QueryBus $bus */
        $bus = $this->app()->make(ApplicationContext::class)->get(QueryBus::class);

        return $bus;
    }
}
