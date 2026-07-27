<?php

declare(strict_types=1);

namespace Firefly\Messaging\Tests\Support;

use Firefly\Messaging\Listener\MessageListenerManifest;
use Firefly\Messaging\MessagingServiceProvider;
use Firefly\Messaging\MessagingWiringProvider;
use Firefly\Messaging\Scanner\MessageListenerScanner;
use Firefly\Testing\FireflyTestCase;
use Firefly\Testing\Fixture\ListenerSpy;
use Illuminate\Foundation\Application;

abstract class MessagingCapstoneTestCase extends FireflyTestCase
{
    /** @return list<class-string> */
    protected function fireflyProviders(): array
    {
        return [
            MessagingServiceProvider::class,
            MessagingWiringProvider::class,
        ];
    }

    protected function messagingProvider(): string
    {
        return 'memory';
    }

    /**
     * Seed config so #[ConditionalOn*] passes (which scan at register time) observe it BEFORE provider
     * registration — same eager-ordering rule the pre-harness resolveApplicationConfiguration() override followed.
     * The queue subclass additionally sets the sync driver.
     *
     * @return array<string, mixed>
     */
    protected function configOverrides(): array
    {
        $config = ['firefly.messaging.provider' => $this->messagingProvider()];
        if ($this->messagingProvider() === 'queue') {
            $config['queue.default'] = 'sync';
        }

        return $config;
    }

    /**
     * Compile the fixture manifest INLINE via the real scanner (exactly what firefly:cache emits) — the harness
     * calls this AFTER provider registration, before boot, so the manifest + ListenerSpy singleton are in place
     * before the wiring pass subscribes and starts the broker.
     */
    protected function defineFireflyEnvironment(Application $app): void
    {
        $descriptors = (new MessageListenerScanner)->scan(['Firefly\\Messaging\\Tests\\Fixtures\\' => dirname(__DIR__).'/Fixtures']);
        $app->instance(MessageListenerManifest::class, new MessageListenerManifest($descriptors));
        $app->singleton(ListenerSpy::class);
    }
}
