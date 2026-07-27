<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Support;

use Firefly\Data\DataServiceProvider;
use Firefly\Data\Proxy\ProxyClassGenerator;
use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Tests\Fixtures\Capstone\CapstoneTransactionalConfiguration;
use Firefly\Data\Transaction\TransactionalManifestCompiler;
use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;

/**
 * Boots a REAL kernel with the shipped DataServiceProvider + the fixture-components provider, over sqlite. The
 * TransactionalManifest for the fixtures is compiled INLINE (the real scanner + TransactionalManifestCompiler,
 * exactly what firefly:cache emits, M15) and each proxy is generated + loaded INLINE (likewise). The compiled
 * manifest FILE is then loaded as a bean by CapstoneTransactionalConfiguration#transactionalManifest() — a
 * competing bean DEFINITION, which is the REAL override seam that makes DataAutoConfiguration's
 * #[ConditionalOnMissingBean(TransactionalManifest::class)] default step aside (the ConditionEvaluator consults
 * the BeanDefinitionRegistry, never a Laravel $app->instance() binding — see that fixture's docblock). This is
 * precisely "the app's compiled manifest overrides the empty default", through the shipped BeanPostProcessor.
 */
abstract class DataCapstoneTestCase extends FireflyDatabaseTestCase
{
    /** @return list<class-string> */
    protected function fireflyProviders(): array
    {
        return [
            DataServiceProvider::class,
            FixtureComponentsProvider::class,
        ];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        $psr4 = ['Firefly\\Data\\Tests\\Fixtures\\Capstone\\' => dirname(__DIR__).'/Fixtures/Capstone'];
        $scanner = new TransactionalScanner;
        $generator = new ProxyClassGenerator;

        foreach ($scanner->scanProxyMethods($psr4) as $target => $methods) {
            $generator->load($target, $methods);
        }

        // firefly:cache emits the compiled manifest to disk; CapstoneTransactionalConfiguration loads it as the
        // bean that overrides DataAutoConfiguration's empty default (both happen before boot resolves the BPP).
        (new TransactionalManifestCompiler)->write($scanner->scan($psr4), CapstoneTransactionalConfiguration::manifestPath());
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('accounts', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
        });
    }
}
