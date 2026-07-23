<?php

declare(strict_types=1);

namespace Firefly\Messaging\Tests\Support;

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Messaging\Listener\MessageListenerManifest;
use Firefly\Messaging\MessagingServiceProvider;
use Firefly\Messaging\MessagingWiringProvider;
use Firefly\Messaging\Scanner\MessageListenerScanner;
use Firefly\Messaging\Tests\Fixtures\Spy;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use LogicException;
use Orchestra\Testbench\TestCase;

abstract class MessagingCapstoneTestCase extends TestCase
{
    /**
     * @param  Application  $app
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            FireflyAutoConfigureServiceProvider::class,
            MessagingServiceProvider::class,
            MessagingWiringProvider::class,
        ];
    }

    protected function messagingProvider(): string
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
     * Seed config + compile the fixture manifest INLINE via the real scanner in resolveApplicationConfiguration
     * (testbench runs this BEFORE provider registration, so the manifest + Spy are in place before the wiring pass
     * subscribes and starts the broker). The queue subclass additionally sets the sync driver.
     *
     * @param  Application  $app
     */
    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        /** @var Repository $config */
        $config = $app->make('config');
        $config->set('firefly.messaging.provider', $this->messagingProvider());
        if ($this->messagingProvider() === 'queue') {
            $config->set('queue.default', 'sync');
        }

        $descriptors = (new MessageListenerScanner)->scan(['Firefly\\Messaging\\Tests\\Fixtures\\' => dirname(__DIR__).'/Fixtures']);
        $app->instance(MessageListenerManifest::class, new MessageListenerManifest($descriptors));
        $app->singleton(Spy::class);
    }
}
