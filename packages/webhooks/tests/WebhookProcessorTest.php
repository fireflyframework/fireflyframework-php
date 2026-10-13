<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Eda\EventPublisher;
use Firefly\Kernel\Exception\Infrastructure\ServiceUnavailableException;
use Firefly\Testing\Double\RecordingEventPublisher;
use Firefly\Webhooks\Exception\WebhookRejectedException;
use Firefly\Webhooks\Idempotency\InMemoryWebhookIdempotencyStore;
use Firefly\Webhooks\Signature\SignatureValidatorFactory;
use Firefly\Webhooks\Tests\Support\WebhookRequests;
use Firefly\Webhooks\WebhookProcessor;
use Firefly\Webhooks\WebhooksSettings;
use Illuminate\Config\Repository;

/**
 * @return array{0: WebhookProcessor, 1: InMemoryWebhookIdempotencyStore}
 */
function w4Processor(EventPublisher $publisher): array
{
    $settings = WebhooksSettings::fromConfig(new Config(new Repository(['firefly' => ['webhooks' => [
        'enabled' => true,
        'max-body-bytes' => 64,
        'providers' => ['open' => ['validator' => 'none', 'event-type-field' => 'event.kind', 'idempotency-field' => 'event.id']],
    ]]])));
    $store = new InMemoryWebhookIdempotencyStore;

    return [new WebhookProcessor($settings, new SignatureValidatorFactory(static fn (): int => time()), $store, $publisher), $store];
}

it('publishes an accepted webhook with its type, destination and idempotency key', function () {
    $events = new RecordingEventPublisher;
    [$processor] = w4Processor($events);

    $result = $processor->process(WebhookRequests::make('open', '{"event":{"kind":"order.paid","id":"d-1"}}'));

    expect($result->status)->toBe('accepted')
        ->and($result->httpStatus())->toBe(202)
        ->and($result->eventType)->toBe('webhook.open.order.paid')
        ->and($events->published[0]['destination'])->toBe('webhooks.open')
        ->and($events->published[0]['eventType'])->toBe('webhook.open.order.paid')
        ->and($events->published[0]['payload']['idempotencyKey'])->toBe('d-1')
        ->and($events->published[0]['payload']['body'])->toBe(['event' => ['kind' => 'order.paid', 'id' => 'd-1']])
        ->and($events->published[0]['headers']['x-webhook-provider'])->toBe('open');
});

it('answers a repeated delivery as a duplicate and publishes nothing more', function () {
    $events = new RecordingEventPublisher;
    [$processor] = w4Processor($events);
    $processor->process(WebhookRequests::make('open', '{"event":{"kind":"a","id":"d-1"}}'));

    $again = $processor->process(WebhookRequests::make('open', '{"event":{"kind":"a","id":"d-1"}}'));

    expect($again->status)->toBe('duplicate')->and($again->httpStatus())->toBe(200)->and($events->published)->toHaveCount(1);
});

it('refuses an unknown provider, an oversized body and malformed JSON', function () {
    [$processor] = w4Processor(new RecordingEventPublisher);

    foreach ([
        'WEBHOOK_PROVIDER_NOT_FOUND' => WebhookRequests::make('nobody', '{}'),
        'WEBHOOK_PAYLOAD_TOO_LARGE' => WebhookRequests::make('open', str_repeat('x', 65)),
        'WEBHOOK_PAYLOAD_INVALID' => WebhookRequests::make('open', '{"broken'),
    ] as $code => $request) {
        $refused = null;
        try {
            $processor->process($request);
        } catch (WebhookRejectedException $e) {
            $refused = $e->errorCode();
        }

        expect($refused)->toBe($code);
    }
});

it('releases the claim when the publish fails, so the provider retry is processed', function () {
    $broken = new class implements EventPublisher
    {
        public int $calls = 0;

        public function subscribe(string $eventTypePattern, callable $handler): void {}

        public function publish(string $destination, string $eventType, array $payload, array $headers = []): void
        {
            $this->calls++;

            throw new RuntimeException('broker down');
        }

        public function start(): void {}

        public function stop(): void {}
    };
    [$processor] = w4Processor($broken);
    $request = WebhookRequests::make('open', '{"event":{"kind":"a","id":"d-9"}}');

    expect(fn () => $processor->process($request))->toThrow(ServiceUnavailableException::class)
        ->and(fn () => $processor->process($request))->toThrow(ServiceUnavailableException::class)
        ->and($broken->calls)->toBe(2);
});
