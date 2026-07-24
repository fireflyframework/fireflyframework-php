<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\Support;

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Cqrs\Handler\HandlerManifest;
use Firefly\Cqrs\Scanner\HandlerScanner;
use Firefly\Cqrs\Tests\CapstoneFixtures\Banking\BankingCqrsConfiguration;
use Firefly\Data\DataServiceProvider;
use Firefly\Data\Proxy\ProxyClassGenerator;
use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Transaction\TransactionalManifestCompiler;
use Firefly\Eda\EventPublisher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Orchestra\Testbench\TestCase;

/**
 * Boots the FULL write path over testbench + sqlite :memory:: firefly/data (M8 tx + after-commit dispatch) +
 * firefly/cqrs (bus + wiring + bridge) + the Banking fixtures. The bridge sink is a FakeEventPublisher bound as the
 * M9 EventPublisher BEFORE providers boot (so cqrs's commandEventPublisher gates onto it and picks EdaCommandEventPublisher).
 * The #[Transactional] proxies + manifest and the HandlerManifest are compiled INLINE in resolveApplicationConfiguration
 * (what firefly:cache emits, M15) — testbench runs it BEFORE provider registration, so the overrides are in place
 * before the boot passes wire anything. Mirrors packages/data/tests/Support/MilestoneCapstoneTestCase.php.
 */
abstract class CqrsCapstoneTestCase extends TestCase
{
    public FakeEventPublisher $eventBus;

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            FireflyAutoConfigureServiceProvider::class,
            DataServiceProvider::class,
            CqrsServiceProvider::class,
            CqrsWiringProvider::class,
            BankingComponentsProvider::class,
        ];
    }

    /**
     * @param  Application  $app
     */
    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        /** @var Repository $config */
        $config = $app->make('config');
        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);

        // The bridge sink — bound before boot so commandEventPublisher() sees an EventPublisher and picks Eda.
        $this->eventBus = new FakeEventPublisher;
        $app->instance(EventPublisher::class, $this->eventBus);

        $psr4 = ['Firefly\\Cqrs\\Tests\\CapstoneFixtures\\Banking\\' => dirname(__DIR__).'/CapstoneFixtures/Banking'];

        // Compile the #[Transactional] proxies + manifest inline so the handlers' handle() methods are proxied.
        $scanner = new TransactionalScanner;
        $generator = new ProxyClassGenerator;
        foreach ($scanner->scanProxyMethods($psr4) as $target => $methods) {
            $generator->load($target, $methods);
        }
        (new TransactionalManifestCompiler)->write($scanner->scan($psr4), BankingCqrsConfiguration::manifestPath());

        // Compile the handler manifest inline (what firefly:cache emits) and bind it — wins over the wiring provider default.
        $handlers = (new HandlerScanner)->scan($psr4);
        $app->instance(HandlerManifest::class, new HandlerManifest($handlers['handlers'], $handlers['destinations']));
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('accounts', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('owner');
            $table->integer('balance');
        });

        // Reset after boot so each it() observes only ITS OWN integration events (boot dispatches non-DomainEvents
        // the bridge already ignores, but reset for cleanliness).
        $this->eventBus->published = [];
    }

    public function capstoneApp(): Application
    {
        if (! $this->app instanceof Application) {
            throw new LogicException('The application has not been booted yet — call this from within a test.');
        }

        return $this->app;
    }
}
