<?php

declare(strict_types=1);

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Messaging\Broker\InMemoryMessageBroker;
use Firefly\Messaging\Listener\MessageListenerManifest;
use Firefly\Messaging\MessageBrokerPort;
use Firefly\Messaging\MessagingServiceProvider;
use Firefly\Messaging\MessagingWiringProvider;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

it('boots a messaging-enabled app with zero #[MessageListener]s on the provider default empty manifest', function () {
    $app = new Application;
    $app->instance('config', new Repository(['firefly' => ['messaging' => []]]));

    $app->register(new FireflyAutoConfigureServiceProvider($app));
    $app->register(new MessagingServiceProvider($app));
    $app->register(new MessagingWiringProvider($app));

    $app->boot();

    /** @var ApplicationContext $context */
    $context = $app->make(ApplicationContext::class);

    expect($context)->toBeInstanceOf(ApplicationContext::class)
        ->and($context->get(MessageBrokerPort::class))->toBeInstanceOf(InMemoryMessageBroker::class)
        ->and($app->make(MessageListenerManifest::class)->all())->toBe([]);
});
