<?php

declare(strict_types=1);

namespace Firefly\Eda\Tests\Support;

use Firefly\Eda\Consumer\EventConsumer;
use Firefly\Eda\EdaConsumerServiceProvider;
use Firefly\Eda\EdaServiceProvider;
use Firefly\Eda\EdaWiringProvider;
use Firefly\Eda\Listener\EventListenerDescriptor;
use Firefly\Eda\Listener\EventListenerManifest;
use Firefly\Eda\Tests\Fixtures\ScriptedEventConsumer;
use Firefly\Testing\FireflyTestCase;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;

/**
 * Harness for firefly:eda:consume. It boots the real eda providers (so EdaConsumerServiceProvider registers the
 * command exactly as package auto-discovery would) and binds a ScriptedEventConsumer as the EventConsumer, which
 * records the destination list the command subscribes it to. The manifest deliberately carries ONLY event-type
 * patterns and NO declared destinations — the pre-fix shape that made the command bind `order.*` as a broker
 * route.
 *
 * Not `final`: Pest's uses() generates a per-test-file class that EXTENDS this one (the ClearCommandTestCase
 * convention, which also records why an anonymous `new class ...::class` cannot be used here).
 */
class EdaConsumeCommandTestCase extends FireflyTestCase
{
    protected ScriptedEventConsumer $consumer;

    /** @return list<class-string> */
    protected function fireflyProviders(): array
    {
        return [EdaServiceProvider::class, EdaWiringProvider::class, EdaConsumerServiceProvider::class];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return ['firefly.eda.provider' => 'memory'];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        $this->consumer = new ScriptedEventConsumer([]);
        $app->instance(EventConsumer::class, $this->consumer);
        $app->instance(EventListenerManifest::class, new EventListenerManifest([
            new EventListenerDescriptor('App\\Listeners\\Orders', 'onOrder', ['order.*'], 0),
        ]));
    }

    /**
     * Run the command with a zero message budget: ConsumerLoop start()s, immediately trips the max-messages
     * bound and stop()s, so subscribe() has run and nothing polls. Goes through Kernel::call() rather than
     * $this->artisan() because InteractsWithConsole::artisan() is declared `PendingCommand|int` and every call
     * site would otherwise need the union handled for PHPStan (firefly/cli's ArtisanAssertions precedent).
     *
     * @param  array<string, mixed>  $parameters
     */
    protected function runConsume(array $parameters = []): int
    {
        /** @var Kernel $kernel */
        $kernel = $this->app()->make(Kernel::class);

        return $kernel->call('firefly:eda:consume', ['--max-messages' => 0] + $parameters);
    }

    /**
     * Set firefly.eda.destinations on the booted app. Typed here rather than at each call site because
     * Container::make('config') is `mixed` to PHPStan and every test would otherwise need its own annotation.
     *
     * @param  array<int, mixed>  $destinations
     */
    protected function setDestinationConfig(array $destinations): void
    {
        /** @var Repository $config */
        $config = $this->app()->make('config');
        $config->set('firefly.eda.destinations', $destinations);
    }
}
