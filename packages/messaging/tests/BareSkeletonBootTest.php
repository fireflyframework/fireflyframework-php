<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Messaging\Broker\InMemoryMessageBroker;
use Firefly\Messaging\Listener\MessageListenerManifest;
use Firefly\Messaging\MessageBrokerPort;
use Firefly\Messaging\MessagingServiceProvider;
use Firefly\Messaging\MessagingWiringProvider;

it('boots a messaging-enabled app with zero #[MessageListener]s on the provider default empty manifest', function () {
    $context = bootFireflyApp(['firefly' => ['messaging' => []]], [MessagingServiceProvider::class, MessagingWiringProvider::class]);

    /** @var MessageListenerManifest $manifest */
    $manifest = $context->get(MessageListenerManifest::class);

    expect($context)->toBeInstanceOf(ApplicationContext::class)
        ->and($context->get(MessageBrokerPort::class))->toBeInstanceOf(InMemoryMessageBroker::class)
        ->and($manifest->all())->toBe([]);
});
