<?php

declare(strict_types=1);

use Firefly\Domain\DomainEvent;
use Firefly\Testing\Double\RecordingCommandBus;
use Firefly\Testing\Double\RecordingCommandEventPublisher;
use Firefly\Testing\Double\RecordingCqrsMetrics;
use Firefly\Testing\Double\StubQueryBus;
use PHPUnit\Framework\ExpectationFailedException;

final class DoOpen {}
final class GetOpen {}
final class NotSent {}

final readonly class SampleDomainEvent extends DomainEvent
{
    public function __construct()
    {
        parent::__construct();
    }
}

it('records commands and returns canned results, and supports toHaveHandledCommand', function () {
    $bus = (new RecordingCommandBus)->willReturn(DoOpen::class, 42);

    $result = $bus->send(new DoOpen);

    expect($result)->toBe(42)
        ->and($bus->sent)->toHaveCount(1)
        ->and($bus->handled(DoOpen::class))->toHaveCount(1);
    // @phpstan-ignore method.notFound
    expect($bus)->toHaveHandledCommand(DoOpen::class);
});

it('stubs queries and records metrics + command-event publishes', function () {
    $queries = (new StubQueryBus)->willReturn(GetOpen::class, ['ok' => true]);
    expect($queries->ask(new GetOpen))->toBe(['ok' => true])
        ->and($queries->asked)->toHaveCount(1);

    $metrics = new RecordingCqrsMetrics;
    $metrics->recordCommandSuccess(new DoOpen, 0.1);
    expect($metrics->commandSuccesses)->toHaveCount(1);

    $publisher = new RecordingCommandEventPublisher;
    $publisher->publish(new SampleDomainEvent);
    expect($publisher->published)->toHaveCount(1);
});

it('rethrows from a preconfigured RecordingCommandEventPublisher', function () {
    $publisher = new RecordingCommandEventPublisher(new RuntimeException('boom'));
    expect(fn () => $publisher->publish(new SampleDomainEvent))->toThrow(RuntimeException::class);
});

it('fails the toHaveHandledCommand expectation when no matching command was sent', function () {
    $bus = new RecordingCommandBus;

    expect(function () use ($bus): void {
        // @phpstan-ignore method.notFound
        expect($bus)->toHaveHandledCommand(NotSent::class);
    })->toThrow(ExpectationFailedException::class);
});
