<?php

declare(strict_types=1);

use Firefly\Cqrs\Command\DefaultCommandBus;
use Firefly\Cqrs\Correlation\CorrelationContext;
use Firefly\Cqrs\Exception\CommandHandlerNotFoundException;
use Firefly\Cqrs\Exception\CommandProcessingException;
use Firefly\Cqrs\Handler\HandlerRegistry;
use Firefly\Cqrs\Metrics\CqrsMetrics;
use Firefly\Cqrs\Security\AllowAllAuthorizer;
use Firefly\Cqrs\Security\CommandAuthorizer;
use Firefly\Cqrs\Validation\MessageValidator;
use Firefly\Kernel\Exception\Business\ValidationException;
use Firefly\Kernel\Exception\Security\AuthorizationException;

/**
 * A CqrsMetrics that records which methods fired.
 *
 * @return CqrsMetrics&object{events: list<string>}
 */
function recordingMetrics(): CqrsMetrics
{
    return new class implements CqrsMetrics
    {
        /** @var list<string> */
        public array $events = [];

        public function recordCommandSuccess(object $command, float $seconds): void
        {
            $this->events[] = 'command.success';
        }

        public function recordCommandFailure(object $command, float $seconds): void
        {
            $this->events[] = 'command.failure';
        }

        public function recordQuerySuccess(object $query, float $seconds): void
        {
            $this->events[] = 'query.success';
        }

        public function recordQueryFailure(object $query, float $seconds): void
        {
            $this->events[] = 'query.failure';
        }
    };
}

/** Build a command bus around a registry + a real correlation context + a metrics recorder. */
function commandBus(HandlerRegistry $registry, CorrelationContext $correlation, CqrsMetrics $metrics, ?CommandAuthorizer $authorizer = null): DefaultCommandBus
{
    return new DefaultCommandBus(
        $registry,
        new MessageValidator(null),
        $authorizer ?? new AllowAllAuthorizer,
        $correlation,
        $metrics,
    );
}

it('runs the handler, returns its result, records success, and restores correlation to null', function () {
    $registry = new HandlerRegistry;
    $correlation = new CorrelationContext;
    $seenId = 'unset';
    $registry->registerCommandHandler('stdClass', function (object $c) use ($correlation, &$seenId): string {
        $seenId = $correlation->currentId(); // an id is active DURING the handler

        return 'ok';
    });
    $metrics = recordingMetrics();

    $result = commandBus($registry, $correlation, $metrics)->send(new stdClass);

    expect($result)->toBe('ok')
        ->and($seenId)->not->toBeNull()          // correlation active during dispatch
        ->and($correlation->currentId())->toBeNull() // restored after
        ->and($metrics->events)->toBe(['command.success']);
});

it('wraps a not-found in CommandProcessingException preserving the framework 500 and records failure', function () {
    $correlation = new CorrelationContext;
    $metrics = recordingMetrics();

    try {
        commandBus(new HandlerRegistry, $correlation, $metrics)->send(new stdClass);
        expect(false)->toBeTrue('expected a CommandProcessingException');
    } catch (CommandProcessingException $e) {
        expect($e->httpStatus())->toBe(500)
            ->and($e->getPrevious())->toBeInstanceOf(CommandHandlerNotFoundException::class);
    }

    expect($metrics->events)->toBe(['command.failure'])
        ->and($correlation->currentId())->toBeNull();
});

it('wraps a handler throwable but PRESERVES a ValidationException 422 (no mask to 500)', function () {
    $registry = new HandlerRegistry;
    $registry->registerCommandHandler('stdClass', function (): void {
        throw new ValidationException('bad');
    });

    try {
        commandBus($registry, new CorrelationContext, recordingMetrics())->send(new stdClass);
        expect(false)->toBeTrue('expected a CommandProcessingException');
    } catch (CommandProcessingException $e) {
        expect($e->httpStatus())->toBe(422)              // preserved, not masked
            ->and($e->getPrevious())->toBeInstanceOf(ValidationException::class);
    }
});

it('re-throws an already-CommandProcessingException AS-IS (no double wrap)', function () {
    $inner = new CommandProcessingException('App\Inner', new RuntimeException('x'));
    $registry = new HandlerRegistry;
    $registry->registerCommandHandler('stdClass', function () use ($inner): void {
        throw $inner;
    });

    try {
        commandBus($registry, new CorrelationContext, recordingMetrics())->send(new stdClass);
        expect(false)->toBeTrue('expected the inner exception');
    } catch (CommandProcessingException $e) {
        expect($e)->toBe($inner)                          // same instance — not re-wrapped
            ->and($e->getPrevious())->toBeInstanceOf(RuntimeException::class);
    }
});

it('runs authorize BEFORE the handler and does not invoke the handler when authorization denies', function () {
    $registry = new HandlerRegistry;
    $ran = false;
    $registry->registerCommandHandler('stdClass', function () use (&$ran): string {
        $ran = true;

        return 'ok';
    });

    $denying = new class implements CommandAuthorizer
    {
        public function authorize(object $command): void
        {
            throw new AuthorizationException('nope');
        }
    };

    try {
        commandBus($registry, new CorrelationContext, recordingMetrics(), $denying)->send(new stdClass);
        expect(false)->toBeTrue('expected a CommandProcessingException');
    } catch (CommandProcessingException $e) {
        expect($e->httpStatus())->toBe(403)              // AuthorizationException category preserved
            ->and($ran)->toBeFalse();                     // handler never ran — authorize is upstream
    }
});
