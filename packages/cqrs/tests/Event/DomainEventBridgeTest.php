<?php

declare(strict_types=1);

use Firefly\Cqrs\Event\DomainEventBridge;
use Firefly\Cqrs\Event\EventFailureStrategy;
use Firefly\Cqrs\Exception\CommandProcessingException;
use Firefly\Cqrs\Tests\EventFixtures\AccountOpened;
use Firefly\Cqrs\Tests\EventFixtures\RecordingCommandEventPublisher;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;

it('delegates a domain event to the CommandEventPublisher', function () {
    $publisher = new RecordingCommandEventPublisher;
    (new DomainEventBridge($publisher))->publish(new AccountOpened('a1', 100));

    expect($publisher->published)->toHaveCount(1)
        ->and($publisher->published[0])->toBeInstanceOf(AccountOpened::class);
});

it('LOG strategy (default) swallows a publish failure and logs it — the command result stands', function () {
    $publisher = new RecordingCommandEventPublisher(new RuntimeException('broker down'));
    $logged = [];
    $logger = new class($logged) implements LoggerInterface
    {
        use LoggerTrait;

        /** @param array<int, string> $sink */
        public function __construct(public array &$sink) {}

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->sink[] = (string) $message;
        }
    };

    (new DomainEventBridge($publisher, EventFailureStrategy::Log, $logger))->publish(new AccountOpened('a1', 100));

    expect($logged)->toHaveCount(1)
        ->and($logged[0])->toContain('AccountOpened'); // swallowed + logged; reaching here (no throw) is the assertion
});

it('RAISE strategy re-throws a publish failure wrapped in CommandProcessingException', function () {
    $publisher = new RecordingCommandEventPublisher(new RuntimeException('broker down'));

    (new DomainEventBridge($publisher, EventFailureStrategy::Raise))->publish(new AccountOpened('a1', 100));
})->throws(CommandProcessingException::class);

it('RAISE re-throws an already-CommandProcessingException as-is', function () {
    $inner = new CommandProcessingException('App\X', new RuntimeException('x'));
    $publisher = new RecordingCommandEventPublisher($inner);

    try {
        (new DomainEventBridge($publisher, EventFailureStrategy::Raise))->publish(new AccountOpened('a1', 100));
        expect(false)->toBeTrue('expected the inner exception');
    } catch (CommandProcessingException $e) {
        expect($e)->toBe($inner); // not double-wrapped
    }
});
