<?php

declare(strict_types=1);

namespace Firefly\Messaging\Tests\Support;

use Firefly\Messaging\DeadLetter\DeadLetterStore;
use Firefly\Messaging\DeadLetter\InMemoryDeadLetterStore;
use Firefly\Messaging\Listener\MessageListenerManifest;
use Firefly\Messaging\MessageBrokerPort;
use Firefly\Messaging\MessagingWiringProvider;
use Firefly\Messaging\Scanner\MessageListenerScanner;
use Firefly\Messaging\Tests\Fixtures\RecordingContainerBroker;
use Firefly\Testing\FireflyTestCase;
use Firefly\Testing\Fixture\ListenerSpy;
use Illuminate\Foundation\Application;

/**
 * The wiring provider over a CONTAINER broker bound before boot (the auto-configuration provider is left out, so
 * nothing competes with the binding): proves the wiring pass hands a container broker the bare listener plus its
 * options, and that firefly:messaging:consume drives poll().
 */
abstract class MessagingContainerCapstoneTestCase extends FireflyTestCase
{
    public RecordingContainerBroker $broker;

    public InMemoryDeadLetterStore $deadLetters;

    /** @return list<class-string> */
    protected function fireflyProviders(): array
    {
        return [MessagingWiringProvider::class];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return ['firefly.messaging.provider' => 'kafka'];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        $this->broker = new RecordingContainerBroker;
        $this->deadLetters = new InMemoryDeadLetterStore;
        $app->instance(MessageBrokerPort::class, $this->broker);
        $app->instance(DeadLetterStore::class, $this->deadLetters);
        $app->instance(MessageListenerManifest::class, new MessageListenerManifest(
            (new MessageListenerScanner)->scan(['Firefly\\Messaging\\Tests\\Fixtures\\' => dirname(__DIR__).'/Fixtures']),
        ));
        $app->singleton(ListenerSpy::class);
    }
}
