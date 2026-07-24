<?php

declare(strict_types=1);

use Firefly\Cqrs\Correlation\CorrelationContext;
use Firefly\Cqrs\Event\EdaCommandEventPublisher;
use Firefly\Cqrs\Event\EventFailureStrategy;
use Firefly\Cqrs\Event\NoOpEventPublisher;
use Firefly\Cqrs\Tests\EventFixtures\AccountOpened;
use Firefly\Eda\EventPublisher;

/**
 * A fake M9 EventPublisher that records every publish() call.
 *
 * @return EventPublisher&object{published: list<array{destination: string, eventType: string, payload: array<string,mixed>, headers: array<string,string>}>}
 */
function fakeEventPublisher(): EventPublisher
{
    return new class implements EventPublisher
    {
        /** @var list<array{destination: string, eventType: string, payload: array<string,mixed>, headers: array<string,string>}> */
        public array $published = [];

        public function subscribe(string $eventTypePattern, callable $handler): void {}

        public function publish(string $destination, string $eventType, array $payload, array $headers = []): void
        {
            $this->published[] = compact('destination', 'eventType', 'payload', 'headers');
        }

        public function start(): void {}

        public function stop(): void {}
    };
}

it('maps a DomainEvent onto EventPublisher::publish with eventType, payload, and the default destination', function () {
    $producer = fakeEventPublisher();
    (new EdaCommandEventPublisher($producer))->publish(new AccountOpened('a1', 100));

    $sent = $producer->published[0];
    expect($sent['destination'])->toBe('cqrs.events')
        ->and($sent['eventType'])->toBe('AccountOpened')          // DomainEvent::eventType() = short class name
        ->and($sent['payload']['accountId'])->toBe('a1')
        ->and($sent['payload']['balance'])->toBe(100)
        // Pin the EXACT payload key set (the bridge's output contract): the subclass fields plus the base
        // DomainEvent's public props from get_object_vars. Dropping occurredAt or leaking a stray field fails here.
        ->and(array_keys($sent['payload']))->toEqualCanonicalizing(['accountId', 'balance', 'eventId', 'occurredAt'])
        ->and($sent['headers'])->toBe([]);                        // no correlation context -> no header
});

it('routes by the #[PublishDomainEvent] destinations map, and an explicit arg overrides the map', function () {
    $producer = fakeEventPublisher();
    $publisher = new EdaCommandEventPublisher($producer, 'cqrs.events', [AccountOpened::class => 'accounts.events']);

    $publisher->publish(new AccountOpened('a1', 100));               // map hit
    $publisher->publish(new AccountOpened('a2', 200), 'explicit.dest'); // explicit arg wins

    expect($producer->published[0]['destination'])->toBe('accounts.events')
        ->and($producer->published[1]['destination'])->toBe('explicit.dest');
});

it('stamps the active correlation id into x-correlation-id when a CorrelationContext is set', function () {
    $producer = fakeEventPublisher();
    $correlation = new CorrelationContext;
    $correlation->begin('corr-123');

    (new EdaCommandEventPublisher($producer, 'cqrs.events', [], $correlation))->publish(new AccountOpened('a1', 100));

    expect($producer->published[0]['headers'])->toBe(['x-correlation-id' => 'corr-123']);
});

it('NoOpEventPublisher drops without touching any producer', function () {
    (new NoOpEventPublisher)->publish(new AccountOpened('a1', 100)); // must not throw
})->throwsNoExceptions();

it('parses the EventFailureStrategy enum from config strings', function () {
    expect(EventFailureStrategy::from('log'))->toBe(EventFailureStrategy::Log)
        ->and(EventFailureStrategy::from('raise'))->toBe(EventFailureStrategy::Raise);
});
