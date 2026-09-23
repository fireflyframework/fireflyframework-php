<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Data\Proxy\MethodInvocation;
use Firefly\Kernel\Exception\Infrastructure\CircuitBreakerOpenException;
use Firefly\Resilience\Method\ResilienceMethodDescriptor;
use Firefly\Resilience\Method\ResilienceMethodInterceptor;
use Firefly\Resilience\ResilienceRegistry;
use Firefly\Resilience\Store\InMemoryResilienceStore;
use Illuminate\Config\Repository;

/**
 * THE ORDER IS A TEST, NOT A COMMENT. Every pattern below is configured so that entering it is OBSERVABLE —
 * a breaker that trips on one failure, a rate limiter holding a single token that never refills — and the
 * recorder writes a line as the method and the recovery run. Asserting on that transcript is the only way to
 * prove nesting: asserting that "it works" would pass under any of the 720 orderings of six wrappers.
 *
 * Three of the five boundaries are pinned here, each by the transcript a DIFFERENT nesting would have
 * produced: Fallback outside Retry (the recovery runs once, after the attempts), Retry outside the breaker
 * (a tripped breaker eats the remaining attempts instead of the method running again), and the breaker
 * outside the rate limiter (an OPEN breaker refuses without spending a token, so the cause the recovery is
 * handed is the breaker's refusal and not the limiter's). The remaining two — TimeLimiter outside Bulkhead —
 * are wall-clock and permit-lease shaped, and are argued rather than raced in ResilienceMethodInterceptor's
 * docblock; what a test CAN pin about them without sleeping is that the published ORDER names them in that
 * position, which the last expectation does.
 *
 * The recorder is an object rather than a by-reference `array &$log`: a reference captured into an anonymous
 * class's promoted property is a shape the 8.3 floor does not document, and a plain object handle reads the
 * same at every call site.
 */
final class ResilienceOrderRecorder
{
    /** @var list<string> */
    public array $lines = [];

    public function record(string $line): void
    {
        $this->lines[] = $line;
    }
}

/**
 * The five instance blocks the descriptors below name, with the policy each proof needs. Defaults are
 * DELIBERATELY generous where the pattern is not the subject (a hundred tokens, five permits, a thirty
 * second budget) so only the pattern under test can produce the transcript being asserted.
 *
 * @param  array<string, mixed>  $overrides  the `firefly.resilience` sub-keys this proof needs instead
 */
function resilienceOrderedInterceptor(array $overrides = []): ResilienceMethodInterceptor
{
    $config = new Config(new Repository(['firefly' => [
        'resilience' => [
            'method' => ['enabled' => true],
            'retry' => ['demo' => ['max-attempts' => 2, 'wait-duration' => '0s']],
            'circuit-breaker' => ['demo' => ['failure-threshold' => 99]],
            'rate-limiter' => ['demo' => ['max-tokens' => 100, 'refill-rate' => 100.0]],
            'bulkhead' => ['demo' => ['max-concurrent' => 5]],
            'time-limiter' => ['demo' => ['timeout' => 30.0]],
            ...$overrides,
        ],
    ]]));

    return new ResilienceMethodInterceptor(ResilienceRegistry::fromConfig($config, new InMemoryResilienceStore), $config);
}

/**
 * One invocation over a hand-built row, with this interceptor as the only link — so proceed() lands straight
 * in the terminal. The proxy slot holds the fixture itself, which is what the fallback is called on.
 *
 * @param  list<mixed>  $arguments
 * @param  callable(mixed...): mixed  $terminal
 */
function resilienceOrderedInvocation(object $target, ResilienceMethodDescriptor $rule, callable $terminal, array $arguments = []): MethodInvocation
{
    return new MethodInvocation(
        $target,
        $rule->class,
        $rule->method,
        $arguments,
        [],
        [ResilienceMethodDescriptor::class => $rule],
        static fn (array $args): mixed => $terminal(...$args),
    );
}

it('nests the patterns Fallback -> Retry -> CircuitBreaker -> RateLimiter -> TimeLimiter -> Bulkhead', function (): void {
    $recorder = new ResilienceOrderRecorder;
    $descriptor = new ResilienceMethodDescriptor('Demo', 'run', 'demo', 'demo', 'demo', 'demo', 'demo', 'recover', [Throwable::class]);

    $target = new class($recorder)
    {
        public function __construct(public ResilienceOrderRecorder $recorder) {}

        public function run(): string
        {
            $this->recorder->record('method');

            throw new RuntimeException('down');
        }

        public function recover(?Throwable $cause = null): string
        {
            $this->recorder->record('fallback');

            return 'recovered';
        }
    };

    $invocation = resilienceOrderedInvocation($target, $descriptor, static fn (): string => $target->run());

    expect(resilienceOrderedInterceptor()->invoke($invocation))->toBe('recovered');

    // Retry(max-attempts: 2) re-runs the method; the fallback runs ONCE, after the retry gave up — which is
    // only possible if Fallback is OUTSIDE Retry. Inside it, the recovery would have absorbed the FIRST
    // attempt and the retry would have "succeeded" on the recovery's value: ['method', 'fallback'].
    expect($recorder->lines)->toBe(['method', 'method', 'fallback']);
});

it('lets the breaker judge every retry attempt, so a tripped breaker eats the ones that are left', function (): void {
    $recorder = new ResilienceOrderRecorder;
    $descriptor = new ResilienceMethodDescriptor('Demo', 'run', null, null, null, 'demo', 'demo', 'recover', [Throwable::class]);

    $target = new class($recorder)
    {
        public function __construct(public ResilienceOrderRecorder $recorder) {}

        public function run(): string
        {
            $this->recorder->record('method');

            throw new RuntimeException('down');
        }

        public function recover(?Throwable $cause = null): string
        {
            $this->recorder->record('fallback:'.($cause === null ? 'none' : $cause::class));

            return 'recovered';
        }
    };

    $interceptor = resilienceOrderedInterceptor([
        'retry' => ['demo' => ['max-attempts' => 3, 'wait-duration' => '0s']],
        'circuit-breaker' => ['demo' => ['failure-threshold' => 1]],
    ]);

    expect($interceptor->invoke(resilienceOrderedInvocation($target, $descriptor, static fn (): string => $target->run())))->toBe('recovered');

    // The method ran ONCE although three attempts were budgeted: the first failure tripped the breaker and
    // attempts two and three were refused by it. Were Retry INSIDE the breaker, the breaker would have
    // admitted once and seen a single outcome, and the transcript would read three 'method' lines.
    expect($recorder->lines)->toBe(['method', 'fallback:none']);
});

it('refuses through an OPEN breaker without spending a rate-limiter token', function (): void {
    $recorder = new ResilienceOrderRecorder;
    $descriptor = new ResilienceMethodDescriptor('Demo', 'run', null, null, 'demo', 'demo', 'demo', 'recover', [Throwable::class], true);

    $target = new class($recorder)
    {
        public function __construct(public ResilienceOrderRecorder $recorder) {}

        public function run(): string
        {
            $this->recorder->record('method');

            throw new RuntimeException('down');
        }

        public function recover(Throwable $cause): string
        {
            $this->recorder->record('fallback:'.$cause::class);

            return 'recovered';
        }
    };

    // ONE token that never refills, and a breaker that trips on the first failure: after the first call the
    // bucket is empty and the breaker is OPEN, so the second call's cause NAMES which of the two refused it.
    $interceptor = resilienceOrderedInterceptor([
        'retry' => ['demo' => ['max-attempts' => 1, 'wait-duration' => '0s']],
        'circuit-breaker' => ['demo' => ['failure-threshold' => 1]],
        'rate-limiter' => ['demo' => ['max-tokens' => 1, 'refill-rate' => 0.0]],
    ]);

    $interceptor->invoke(resilienceOrderedInvocation($target, $descriptor, static fn (): string => $target->run()));
    $interceptor->invoke(resilienceOrderedInvocation($target, $descriptor, static fn (): string => $target->run()));

    // The second call never reached the method, and the cause is the BREAKER's refusal: with the rate limiter
    // outside the breaker, the empty bucket would have refused first and the line would name
    // RateLimitExceededException instead.
    expect($recorder->lines)->toBe([
        'method',
        'fallback:'.RuntimeException::class,
        'fallback:'.CircuitBreakerOpenException::class,
    ]);
});

it('publishes the order it composes, outermost first', function (): void {
    expect(ResilienceMethodInterceptor::ORDER)->toBe(['fallback', 'retry', 'circuitBreaker', 'rateLimiter', 'timeLimiter', 'bulkhead']);
});
