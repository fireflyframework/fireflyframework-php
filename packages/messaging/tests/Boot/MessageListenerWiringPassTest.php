<?php

declare(strict_types=1);

use Firefly\Messaging\DeadLetter\DeadLetterStore;
use Firefly\Messaging\Listener\MessageListenerManifest;
use Firefly\Messaging\MessageBrokerPort;
use Firefly\Messaging\MessagingServiceProvider;
use Firefly\Messaging\MessagingWiringProvider;
use Firefly\Messaging\Scanner\MessageListenerScanner;
use Firefly\Testing\Fixture\ListenerSpy;

it('subscribes compiled #[MessageListener]s onto the broker at boot and starts it (in-memory provider)', function () {
    $descriptors = (new MessageListenerScanner)->scan(['Firefly\\Messaging\\Tests\\Fixtures\\' => dirname(__DIR__).'/Fixtures']);

    $context = bootFireflyApp(
        ['firefly' => ['messaging' => []]],
        [MessagingServiceProvider::class, MessagingWiringProvider::class],
        bindings: [
            MessageListenerManifest::class => new MessageListenerManifest($descriptors),
            ListenerSpy::class => new ListenerSpy,
        ],
    );

    // The wiring pass already called start(); publishing now reaches the consumer (no manual start()).
    /** @var MessageBrokerPort $broker */
    $broker = $context->get(MessageBrokerPort::class);
    $broker->publish('orders', 'bytes');

    /** @var ListenerSpy $spy */
    $spy = $context->get(ListenerSpy::class);
    expect($spy->seen)->toBe(['orders']);
});

/**
 * The wiring layer is where the "policy-free" broker gets its per-listener retry/DLQ policy: the pass wraps each
 * subscriber in a RetryingMessageHandler, using the DESCRIPTOR's OWN retries/retryDelay/deadLetterTopic (unlike
 * eda's config-driven policy), BEFORE subscribe(). This pins that wrap AT the wiring layer — a throwing
 * #[MessageListener] wired at boot must be caught and dead-lettered (not escape publish()), which only holds if
 * the wrap is applied with the descriptor's own policy. Drop the RetryingMessageHandler::wrap in the pass, or
 * hard-code retries: 0 / deadLetterTopic: null instead of reading the descriptor, and this fails (the
 * RuntimeException escapes / the DLQ stays empty).
 */
it('wraps each wired listener in retry/DLQ: a throwing listener is dead-lettered, not escaped', function () {
    $descriptors = (new MessageListenerScanner)->scan(['Firefly\\Messaging\\Tests\\Fixtures\\' => dirname(__DIR__).'/Fixtures']);

    $context = bootFireflyApp(
        ['firefly' => ['messaging' => []]],
        [MessagingServiceProvider::class, MessagingWiringProvider::class],
        bindings: [
            MessageListenerManifest::class => new MessageListenerManifest($descriptors),
            ListenerSpy::class => new ListenerSpy,
        ],
    );

    // FailingConsumer is wired with retries: 2, deadLetterTopic: 'failing.DLT' — always throws.
    /** @var MessageBrokerPort $broker */
    $broker = $context->get(MessageBrokerPort::class);
    $broker->publish('failing', 'bytes');

    /** @var DeadLetterStore $dlq */
    $dlq = $context->get(DeadLetterStore::class);
    $entries = $dlq->all();

    expect($entries)->toHaveCount(1)
        ->and($entries[0]->exceptionMessage)->toBe('consumer boom')
        ->and($entries[0]->message->topic)->toBe('failing.DLT');
});
