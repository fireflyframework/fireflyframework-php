<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Support;

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Data\DataServiceProvider;
use Firefly\Data\Proxy\ProxyClassGenerator;
use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Tests\Fixtures\Ordering\OrderingTransactionalConfiguration;
use Firefly\Data\Transaction\TransactionalManifestCompiler;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Orchestra\Testbench\TestCase;

/**
 * Boots the REAL kernel with the shipped DataServiceProvider + the Ordering fixture provider over sqlite. The
 * TransactionalManifest is compiled INLINE (real scanner + TransactionalManifestCompiler, exactly what firefly:cache
 * emits, M15) and each proxy is generated + loaded INLINE likewise. The compiled manifest FILE is then loaded as a
 * bean by OrderingTransactionalConfiguration#transactionalManifest() — a competing bean DEFINITION, the REAL
 * override seam that makes DataAutoConfiguration's #[ConditionalOnMissingBean(TransactionalManifest::class)]
 * default step aside (mirrors Fixtures/Capstone/CapstoneTransactionalConfiguration for the earlier DataCapstoneTestCase
 * — see that fixture's docblock for why a plain `$app->instance(TransactionalManifest::class, ...)` does NOT work:
 * the ConditionEvaluator consults the BeanDefinitionRegistry, never a Laravel container instance binding, so the
 * empty default would still fire and silently overwrite it at FlushDefinitions). A spy ApplicationEventPublisher IS
 * validly bound via `$app->instance()` before boot (that default is a bound()-guarded provider binding — a
 * different, working mechanism — so the spy wins); DataAutoConfiguration's DomainEventDispatcher bean then publishes
 * to it. HARNESS QUIRK (the M6/M7 lesson): config + the spy + the inline manifest are seeded in
 * resolveApplicationConfiguration (BEFORE the providers boot), never in setUp — testbench builds the container
 * from config first, so a later binding would miss the eager wiring.
 */
abstract class MilestoneCapstoneTestCase extends TestCase
{
    public SpyEventPublisher $spy;

    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            FireflyAutoConfigureServiceProvider::class,
            DataServiceProvider::class,
            OrderingComponentsProvider::class,
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

        $this->spy = new SpyEventPublisher;
        $app->instance(ApplicationEventPublisher::class, $this->spy);

        $psr4 = ['Firefly\\Data\\Tests\\Fixtures\\Ordering\\' => dirname(__DIR__).'/Fixtures/Ordering'];
        $scanner = new TransactionalScanner;
        $generator = new ProxyClassGenerator;

        foreach ($scanner->scanProxyMethods($psr4) as $target => $methods) {
            $generator->load($target, $methods);
        }

        // firefly:cache emits the compiled manifest to disk; OrderingTransactionalConfiguration loads it as the
        // bean that overrides DataAutoConfiguration's empty default (both happen before boot resolves the BPP).
        (new TransactionalManifestCompiler)->write($scanner->scan($psr4), OrderingTransactionalConfiguration::manifestPath());
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('orders', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('status');
            $table->string('created_at');
        });

        // Boot itself publishes framework lifecycle events (ContextRefreshedEvent, ApplicationReadyEvent) through
        // the SAME ApplicationEventPublisher port — and the spy is bound as the sole implementation from BEFORE
        // boot, so it legitimately captures them too. Reset the recorder once boot has fully settled so each it()
        // starts from an empty buffer and observes only ITS OWN domain events, per the capstone's assertions.
        $this->spy->events = [];
    }

    /**
     * A typed, narrowed accessor over the inherited (untyped, protected) `$app` property that only holds a real
     * Application once setUp() has run. Gives Pest test closures a real, non-nullable Application without reaching
     * into a protected property from outside the class (mirrors DataCapstoneTestCase::capstoneApp() and
     * firefly/scheduling's SchedulingCapstoneTestCase/firefly/context's LaraflyTestCase) — PHPStan cannot otherwise
     * resolve `$this->app`'s type inside a Pest `it()` closure bound to this class via `uses()`.
     */
    public function milestoneApp(): Application
    {
        if (! $this->app instanceof Application) {
            throw new LogicException('The application has not been booted yet — call this from within a test.');
        }

        return $this->app;
    }
}
