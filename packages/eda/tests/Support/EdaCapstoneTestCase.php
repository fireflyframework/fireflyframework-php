<?php

declare(strict_types=1);

namespace Firefly\Eda\Tests\Support;

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Eda\EdaServiceProvider;
use Firefly\Eda\EdaWiringProvider;
use Firefly\Eda\Listener\EventListenerManifest;
use Firefly\Eda\Scanner\EventListenerScanner;
use Firefly\Eda\Tests\Fixtures\Spy;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use LogicException;
use Orchestra\Testbench\TestCase;

abstract class EdaCapstoneTestCase extends TestCase
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            FireflyAutoConfigureServiceProvider::class,
            EdaServiceProvider::class,
            EdaWiringProvider::class,
        ];
    }

    /** The eda provider under test — `memory` here; the queue subclass overrides to `queue`. */
    protected function edaProvider(): string
    {
        return 'memory';
    }

    public function capstoneApp(): Application
    {
        if (! $this->app instanceof Application) {
            throw new LogicException('The application has not been booted yet — call this from within a test.');
        }

        return $this->app;
    }

    /**
     * Seed config + compile the fixture manifest INLINE via the real scanner (exactly what firefly:cache emits,
     * M15) in resolveApplicationConfiguration — testbench runs this BEFORE provider registration, so the manifest
     * override + Spy singleton are in place before EventListenerWiringPass subscribes. retries=2/retry-delay=0
     * exercises the retry→DLQ path without slowing the suite. The queue subclass additionally sets the sync driver.
     *
     * @param  Application  $app
     */
    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        /** @var Repository $config */
        $config = $app->make('config');
        $config->set('firefly.eda.provider', $this->edaProvider());
        $config->set('firefly.eda.retries', 2);
        $config->set('firefly.eda.retry_delay', 0);
        if ($this->edaProvider() === 'queue') {
            $config->set('queue.default', 'sync');
        }

        $descriptors = (new EventListenerScanner)->scan(['Firefly\\Eda\\Tests\\Fixtures\\' => dirname(__DIR__).'/Fixtures']);
        $app->instance(EventListenerManifest::class, new EventListenerManifest($descriptors));
        $app->singleton(Spy::class);
    }
}
