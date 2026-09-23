<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Data\Proxy\MethodInvocation;
use Firefly\Kernel\Exception\Infrastructure\CircuitBreakerOpenException;
use Firefly\Kernel\Exception\Infrastructure\RateLimitExceededException;
use Firefly\Kernel\Exception\Infrastructure\TimeoutException;
use Firefly\Resilience\Exception\BulkheadFullException;
use Firefly\Resilience\Method\ResilienceMethodDescriptor;
use Firefly\Resilience\Method\ResilienceMethodInterceptor;
use Firefly\Resilience\ResilienceRegistry;
use Firefly\Resilience\Store\InMemoryResilienceStore;
use Illuminate\Config\Repository;

/**
 * The single-link unit half of the advice: the interceptor is the only link, so proceed() lands straight in
 * the terminal closure and every assertion is about what THIS class did. The composition of all six patterns
 * is the sibling ResilienceCompositionOrderTest's subject; here each pattern is exercised alone, because a
 * row that names one pattern is by far the commonest shape an application writes and the wrapper for it must
 * be exactly the programmatic component this package already ships.
 *
 * EACH PATTERN IS EXERCISED ALONE, AND EACH BY AN INSTANCE THAT CAN REFUSE. That second half is what makes
 * the first one worth anything: the order test and the capstones configure a bulkhead, a rate limiter and a
 * time limiter that are DELIBERATELY permissive (five permits, a hundred tokens, a thirty-second budget)
 * because there the subject is something else — and a permissive instance cannot tell a wrapper that is
 * present from one that is not. Deleting all three `if` blocks from invoke() once left the whole monorepo
 * green while a #[Bulkhead], a #[RateLimiter] and a #[TimeLimiter] guarded nothing at all: exactly the
 * unenforced-but-green hole this diff's own commit message calls out for `new ResilienceAdviceSource`. The
 * three refusal assertions below are the pin, one per branch, with only the pattern under test in the row.
 *
 * @param  array<string, mixed>  $resilience  the `firefly.resilience` tree this interceptor reads
 */
function resilienceInterceptor(array $resilience): ResilienceMethodInterceptor
{
    $config = new Config(new Repository(['firefly' => ['resilience' => $resilience]]));

    return new ResilienceMethodInterceptor(ResilienceRegistry::fromConfig($config, new InMemoryResilienceStore), $config);
}

/**
 * @param  list<mixed>  $arguments
 * @param  callable(mixed...): mixed  $terminal
 */
function resilienceInvocation(object $target, ResilienceMethodDescriptor $rule, callable $terminal, array $arguments = []): MethodInvocation
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

it('re-invokes a #[Retry]-guarded method until it succeeds', function (): void {
    $attempts = 0;
    $rule = new ResilienceMethodDescriptor('Demo', 'run', retry: 'demo');

    $interceptor = resilienceInterceptor(['retry' => ['demo' => ['max-attempts' => 3, 'wait-duration' => '0s']]]);

    $result = $interceptor->invoke(resilienceInvocation(new stdClass, $rule, static function () use (&$attempts): string {
        $attempts++;

        if ($attempts < 3) {
            throw new RuntimeException('flaky');
        }

        return 'ok';
    }));

    expect($result)->toBe('ok')->and($attempts)->toBe(3);
});

it('refuses the second call through a #[CircuitBreaker] that tripped on the first, without reaching the method', function (): void {
    $calls = 0;
    $rule = new ResilienceMethodDescriptor('Demo', 'run', circuitBreaker: 'demo');

    $interceptor = resilienceInterceptor(['circuit-breaker' => ['demo' => ['failure-threshold' => 1]]]);
    $terminal = static function () use (&$calls): string {
        $calls++;

        throw new RuntimeException('down');
    };

    expect(static fn (): mixed => $interceptor->invoke(resilienceInvocation(new stdClass, $rule, $terminal)))
        ->toThrow(RuntimeException::class);

    // The breaker is OPEN now: the second call is refused by the wrapper, so the terminal is never reached.
    expect(static fn (): mixed => $interceptor->invoke(resilienceInvocation(new stdClass, $rule, $terminal)))
        ->toThrow(CircuitBreakerOpenException::class)
        ->and($calls)->toBe(1);
});

it('refuses a #[Bulkhead]-guarded method with no permit free, without reaching it', function (): void {
    $calls = 0;
    $rule = new ResilienceMethodDescriptor('Demo', 'run', bulkhead: 'demo');

    // `max-concurrent: 0` is a bulkhead that can never lease a permit, which is the smallest instance that
    // REFUSES — and refusing is the only observable difference between this wrapper being there and not.
    $interceptor = resilienceInterceptor(['bulkhead' => ['demo' => ['max-concurrent' => 0]]]);

    expect(static fn (): mixed => $interceptor->invoke(resilienceInvocation(new stdClass, $rule, static function () use (&$calls): string {
        $calls++;

        return 'ok';
    })))->toThrow(BulkheadFullException::class)
        // The permit is taken BEFORE the call, so a full bulkhead never reaches the method.
        ->and($calls)->toBe(0);
});

it('refuses the call that finds a #[RateLimiter] out of tokens, after letting the first one through', function (): void {
    $calls = 0;
    $rule = new ResilienceMethodDescriptor('Demo', 'run', rateLimiter: 'demo');

    // ONE token and a refill rate of zero: the first call spends the bucket and nothing ever puts a token
    // back, so the second is refused. Asserting BOTH halves is what separates "the wrapper is there" from
    // "the wrapper is there and refuses everything".
    $interceptor = resilienceInterceptor(['rate-limiter' => ['demo' => ['max-tokens' => 1, 'refill-rate' => 0.0]]]);
    $terminal = static function () use (&$calls): string {
        $calls++;

        return 'ok';
    };

    expect($interceptor->invoke(resilienceInvocation(new stdClass, $rule, $terminal)))->toBe('ok')
        ->and($calls)->toBe(1);

    expect(static fn (): mixed => $interceptor->invoke(resilienceInvocation(new stdClass, $rule, $terminal)))
        ->toThrow(RateLimitExceededException::class)
        ->and($calls)->toBe(1);
});

it('fails a #[TimeLimiter]-guarded method that overruns its budget', function (): void {
    $rule = new ResilienceMethodDescriptor('Demo', 'run', timeLimiter: 'demo');

    // A sub-second budget takes TimeLimiter's post-hoc path (pcntl_alarm's granularity is one second), so the
    // method runs to completion and the overrun is measured afterwards — which is the behaviour every FPM
    // deployment gets, and the one worth pinning here. The margin is 2x: 50 ms of budget against 100 ms of
    // work, so the assertion cannot flake on a loaded machine in the direction that would hide the defect.
    $interceptor = resilienceInterceptor(['time-limiter' => ['demo' => ['timeout' => 0.05]]]);

    expect(static fn (): mixed => $interceptor->invoke(resilienceInvocation(new stdClass, $rule, static function (): string {
        usleep(100_000);

        return 'ok';
    })))->toThrow(TimeoutException::class);
});

it('lets an exception no #[Fallback] `on:` entry names propagate untouched', function (): void {
    $rule = new ResilienceMethodDescriptor('Demo', 'run', retry: 'demo', fallbackMethod: 'recover', fallbackOn: [DomainException::class]);

    $target = new class
    {
        public bool $recovered = false;

        public function recover(): string
        {
            $this->recovered = true;

            return 'recovered';
        }
    };

    $interceptor = resilienceInterceptor(['retry' => ['demo' => ['max-attempts' => 1, 'wait-duration' => '0s']]]);

    expect(static fn (): mixed => $interceptor->invoke(resilienceInvocation($target, $rule, static fn (): string => throw new RuntimeException('down'))))
        ->toThrow(RuntimeException::class, 'down')
        ->and($target->recovered)->toBeFalse();
});

it('hands the caught Throwable to a recovery whose parameter after the guarded arguments accepts one', function (): void {
    $rule = new ResilienceMethodDescriptor(
        'Demo',
        'run',
        retry: 'demo',
        fallbackMethod: 'recover',
        fallbackOn: [Throwable::class],
        fallbackAcceptsThrowable: true,
    );

    $target = new class
    {
        /** @var list<mixed> */
        public array $received = [];

        public function recover(string $account, ?Throwable $cause = null): string
        {
            $this->received = [$account, $cause];

            return 'queued';
        }
    };

    $interceptor = resilienceInterceptor(['retry' => ['demo' => ['max-attempts' => 1, 'wait-duration' => '0s']]]);

    $result = $interceptor->invoke(resilienceInvocation(
        $target,
        $rule,
        static fn (mixed ...$args): string => throw new RuntimeException('gateway down'),
        ['acct-1'],
    ));

    expect($result)->toBe('queued')
        ->and($target->received[0])->toBe('acct-1')
        ->and($target->received[1])->toBeInstanceOf(RuntimeException::class);
});

it('calls a recovery that takes only the guarded arguments without appending the Throwable', function (): void {
    $rule = new ResilienceMethodDescriptor(
        'Demo',
        'run',
        retry: 'demo',
        fallbackMethod: 'recover',
        fallbackOn: [Throwable::class],
        fallbackAcceptsThrowable: false,
    );

    $target = new class
    {
        /** @var list<mixed> */
        public array $received = [];

        public function recover(string $account, int $cents): string
        {
            $this->received = [$account, $cents];

            return 'queued';
        }
    };

    $interceptor = resilienceInterceptor(['retry' => ['demo' => ['max-attempts' => 1, 'wait-duration' => '0s']]]);

    $reached = [];
    $result = $interceptor->invoke(resilienceInvocation(
        $target,
        $rule,
        static function (mixed ...$args) use (&$reached): string {
            $reached = $args;

            throw new RuntimeException('gateway down');
        },
        ['acct-1', 500],
    ));

    // The recovery is handed exactly what the METHOD was handed — no more, because its slot after them does
    // not accept a Throwable, and no less.
    expect($result)->toBe('queued')
        ->and($reached)->toBe(['acct-1', 500])
        ->and($target->received)->toBe(['acct-1', 500]);
});

it('proceeds straight to the method with the master key off, without asking the registry for anything', function (): void {
    $calls = 0;
    $rule = new ResilienceMethodDescriptor('Demo', 'run', 'demo', 'demo', 'demo', 'demo', 'demo', 'recover', [Throwable::class]);

    // NOT ONE instance is configured, so any registry lookup — for any of the six patterns the row names —
    // would throw ConfigurationException. Reaching the terminal is therefore proof that the key short-circuits
    // BEFORE the registry is touched, not merely that the patterns were permissive.
    $interceptor = resilienceInterceptor(['method' => ['enabled' => false]]);

    $result = $interceptor->invoke(resilienceInvocation(new stdClass, $rule, static function () use (&$calls): string {
        $calls++;

        return 'ok';
    }));

    expect($result)->toBe('ok')->and($calls)->toBe(1);
});

it('leaves a method with no compiled resilience row alone', function (): void {
    $invocation = new MethodInvocation(
        new stdClass,
        'Demo',
        'run',
        [],
        [],
        [],
        static fn (array $args): string => 'untouched',
    );

    expect(resilienceInterceptor([])->invoke($invocation))->toBe('untouched');
});
