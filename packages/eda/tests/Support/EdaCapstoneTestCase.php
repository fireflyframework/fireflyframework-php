<?php

declare(strict_types=1);

namespace Firefly\Eda\Tests\Support;

use Firefly\Eda\EdaServiceProvider;
use Firefly\Eda\EdaWiringProvider;
use Firefly\Eda\Listener\EventListenerManifest;
use Firefly\Eda\Scanner\EventListenerScanner;
use Firefly\Testing\FireflyTestCase;
use Firefly\Testing\Fixture\ListenerSpy;
use Illuminate\Foundation\Application;

abstract class EdaCapstoneTestCase extends FireflyTestCase
{
    /** @return list<class-string> */
    protected function fireflyProviders(): array
    {
        return [
            EdaServiceProvider::class,
            EdaWiringProvider::class,
        ];
    }

    /** The eda provider under test — `memory` here; the queue subclass overrides to `queue`. */
    protected function edaProvider(): string
    {
        return 'memory';
    }

    /**
     * Seed config so #[ConditionalOn*] passes (which scan at register time) observe it BEFORE provider
     * registration — same eager-ordering rule the pre-harness resolveApplicationConfiguration() override followed.
     * retries=2/retry-delay=0 exercises the retry→DLQ path without slowing the suite. The queue subclass
     * additionally sets the sync driver.
     *
     * @return array<string, mixed>
     */
    protected function configOverrides(): array
    {
        $config = [
            'firefly.eda.provider' => $this->edaProvider(),
            'firefly.eda.retries' => 2,
            'firefly.eda.retry_delay' => 0,
        ];
        if ($this->edaProvider() === 'queue') {
            $config['queue.default'] = 'sync';
        }

        return $config;
    }

    /**
     * Compile the fixture manifest INLINE via the real scanner (exactly what firefly:cache emits, M15) — the
     * harness calls this AFTER provider registration, before boot, so the manifest override + ListenerSpy
     * singleton are in place before EventListenerWiringPass subscribes.
     */
    protected function defineFireflyEnvironment(Application $app): void
    {
        $descriptors = (new EventListenerScanner)->scan(['Firefly\\Eda\\Tests\\Fixtures\\' => dirname(__DIR__).'/Fixtures']);
        $app->instance(EventListenerManifest::class, new EventListenerManifest($descriptors));
        $app->singleton(ListenerSpy::class);
    }
}
