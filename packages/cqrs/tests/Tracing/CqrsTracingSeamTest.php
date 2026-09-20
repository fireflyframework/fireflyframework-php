<?php

declare(strict_types=1);

use Firefly\Cqrs\Cache\NoOpQueryCache;
use Firefly\Cqrs\Command\DefaultCommandBus;
use Firefly\Cqrs\Correlation\CorrelationContext;
use Firefly\Cqrs\Exception\CommandProcessingException;
use Firefly\Cqrs\Handler\HandlerRegistry;
use Firefly\Cqrs\Metrics\NoOpCqrsMetrics;
use Firefly\Cqrs\Query\DefaultQueryBus;
use Firefly\Cqrs\Security\AllowAllAuthorizer;
use Firefly\Cqrs\Tracing\CqrsTracing;
use Firefly\Cqrs\Tracing\NoOpCqrsTracing;
use Firefly\Cqrs\Validation\MessageValidator;

/**
 * A CqrsTracing that records which messages it wrapped and whether the invocation ran INSIDE the wrap.
 *
 * @return CqrsTracing&object{wrapped: list<string>, inside: bool}
 */
function recordingCqrsTracing(): CqrsTracing
{
    return new class implements CqrsTracing
    {
        /** @var list<string> */
        public array $wrapped = [];

        public bool $inside = false;

        public function traceCommand(object $command, callable $invocation): mixed
        {
            return $this->wrap('command:'.$command::class, $invocation);
        }

        public function traceQuery(object $query, callable $invocation): mixed
        {
            return $this->wrap('query:'.$query::class, $invocation);
        }

        private function wrap(string $label, callable $invocation): mixed
        {
            $this->wrapped[] = $label;
            $this->inside = true;

            try {
                return $invocation();
            } finally {
                $this->inside = false;
            }
        }
    };
}

it('runs the command pipeline inside the tracing seam and hands the result back', function () {
    $registry = new HandlerRegistry;
    $tracing = recordingCqrsTracing();
    $seenInside = null;
    $registry->registerCommandHandler('stdClass', function () use ($tracing, &$seenInside): string {
        $seenInside = $tracing->inside;

        return 'ok';
    });

    $bus = new DefaultCommandBus($registry, new MessageValidator(null), new AllowAllAuthorizer, new CorrelationContext, new NoOpCqrsMetrics, $tracing);

    expect($bus->send(new stdClass))->toBe('ok')
        ->and($seenInside)->toBeTrue()
        ->and($tracing->wrapped)->toBe(['command:stdClass'])
        ->and($tracing->inside)->toBeFalse();
});

it('lets a handler failure propagate through the seam, still wrapped as a CommandProcessingException', function () {
    $registry = new HandlerRegistry;
    $registry->registerCommandHandler('stdClass', function (): never {
        throw new RuntimeException('boom');
    });
    $tracing = recordingCqrsTracing();
    $bus = new DefaultCommandBus($registry, new MessageValidator(null), new AllowAllAuthorizer, new CorrelationContext, new NoOpCqrsMetrics, $tracing);

    expect(fn () => $bus->send(new stdClass))->toThrow(CommandProcessingException::class)
        ->and($tracing->wrapped)->toBe(['command:stdClass'])
        ->and($tracing->inside)->toBeFalse();
});

it('wraps queries too, and defaults to the NoOp seam when none is given', function () {
    $registry = new HandlerRegistry;
    $registry->registerQueryHandler('stdClass', fn (): int => 42);
    $tracing = recordingCqrsTracing();

    $traced = new DefaultQueryBus($registry, new MessageValidator(null), new AllowAllAuthorizer, new CorrelationContext, new NoOpCqrsMetrics, new NoOpQueryCache, null, $tracing);
    $plain = new DefaultQueryBus($registry, new MessageValidator(null), new AllowAllAuthorizer, new CorrelationContext, new NoOpCqrsMetrics, new NoOpQueryCache);

    expect($traced->ask(new stdClass))->toBe(42)
        ->and($tracing->wrapped)->toBe(['query:stdClass'])
        ->and($plain->ask(new stdClass))->toBe(42)
        ->and((new NoOpCqrsTracing)->traceCommand(new stdClass, fn (): string => 'through'))->toBe('through');
});
