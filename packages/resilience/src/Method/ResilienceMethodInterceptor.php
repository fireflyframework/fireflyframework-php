<?php

declare(strict_types=1);

namespace Firefly\Resilience\Method;

use Closure;
use Firefly\Config\Config;
use Firefly\Data\Proxy\MethodInterceptor;
use Firefly\Data\Proxy\MethodInvocation;
use Firefly\Resilience\Fallback as FallbackPattern;
use Firefly\Resilience\ResilienceRegistry;
use Throwable;

/**
 * The proxy link that applies Resilience4j's patterns to a method — by WRAPPING the programmatic components
 * this package already ships, never by reimplementing one. Every policy decision (how many attempts, when a
 * breaker trips, how a bulkhead leases a permit) is made by the same Retry / CircuitBreaker / RateLimiter /
 * Bulkhead / TimeLimiter / Fallback object `$registry->retry('payments')->call(...)` returns, so the two call
 * styles cannot diverge: there is one implementation of each pattern in this package, and an attribute is a
 * second way to reach it.
 *
 * THE COMPOSITION ORDER IS THE CONTRACT, and it is Resilience4j's. Applying `Decorators`' builder sequence —
 * `withBulkhead()` then `withTimeLimiter()` then `withRateLimiter()` then `withCircuitBreaker()` then
 * `withRetry()` then `withFallback()`, each wrapping what came before — yields exactly the nesting
 * Resilience4j documents for its aspects:
 *
 *     Fallback ( Retry ( CircuitBreaker ( RateLimiter ( TimeLimiter ( Bulkhead ( method ) ) ) ) ) )
 *
 * and each layer is where it is for a reason a reader can check:
 *
 *   - FALLBACK outermost, because it must see the exception the retry finally gave up on. Inside Retry it
 *     would recover every failed attempt and the retry would "succeed" on the recovery value, so nothing
 *     would ever be retried — the failure mode that makes a fallback and a retry silently cancel out.
 *   - RETRY outside the breaker, because each attempt must be a fresh call the breaker gets to judge:
 *     that is how a retry storm trips the breaker instead of hiding from it.
 *   - CIRCUIT BREAKER outside the rate limiter, because an OPEN breaker must refuse in microseconds without
 *     spending one of the limiter's tokens on a call that is not going to happen.
 *   - TIME LIMITER outside the bulkhead so the timeout covers only the work, not the wait for a permit;
 *     a caller queued behind a full bulkhead is not a slow downstream.
 *   - BULKHEAD innermost, so a permit is held for the shortest possible window — never while a call sits
 *     in the rate limiter's queue or waits out a breaker's half-open lease. A permit held during a wait is
 *     a permit that is not protecting anything.
 *
 * Three of those five boundaries are asserted on a recorded transcript by
 * `packages/resilience/tests/Method/ResilienceCompositionOrderTest.php`, which is why they are a test rather
 * than this paragraph.
 *
 * ADVICE ORDER 200 — inside security's 100, outside the transaction's 1000. Both halves matter. A call a
 * #[PreAuthorize] refuses must NOT consume a retry budget, a bulkhead permit or a breaker outcome: a 403 is
 * a caller's mistake, not a downstream failure, and counting it as one is how a permissions bug trips a
 * production breaker. And a retry must open a NEW transaction per attempt rather than re-running inside a
 * transaction that is already doomed — which is only true while this link sits outside the transactional
 * one. Metrics (50) sit outside all of it, so a timer measures every attempt and the wait between them:
 * the latency the caller experienced, not the last attempt's.
 *
 * The master key is read LIVE on every call, like MethodSecurityInterceptor's, because a test flips it after
 * boot and the proxy already holds this instance.
 */
final class ResilienceMethodInterceptor implements MethodInterceptor
{
    /** The composition, outermost first — published so the docs and the order test read it rather than restate it. */
    public const array ORDER = ['fallback', 'retry', 'circuitBreaker', 'rateLimiter', 'timeLimiter', 'bulkhead'];

    public function __construct(
        private readonly ResilienceRegistry $registry,
        private readonly Config $config,
    ) {}

    public function invoke(MethodInvocation $invocation): mixed
    {
        $rule = $invocation->descriptor(ResilienceMethodDescriptor::class);
        if ($rule === null || ! $this->config->bool('firefly.resilience.method.enabled', true)) {
            return $invocation->proceed();
        }

        // Built INNERMOST FIRST, each wrapper closing over the one before it — the Decorators idiom. The
        // resulting call order is the reverse, which is self::ORDER.
        $call = static fn (): mixed => $invocation->proceed();

        if ($rule->bulkhead !== null) {
            $bulkhead = $this->registry->bulkhead($rule->bulkhead);
            $call = static fn (): mixed => $bulkhead->call($call);
        }

        if ($rule->timeLimiter !== null) {
            $limiter = $this->registry->timeLimiter($rule->timeLimiter);
            $call = static fn (): mixed => $limiter->call($call);
        }

        if ($rule->rateLimiter !== null) {
            $rateLimiter = $this->registry->rateLimiter($rule->rateLimiter);
            $call = static fn (): mixed => $rateLimiter->call($call);
        }

        if ($rule->circuitBreaker !== null) {
            $breaker = $this->registry->circuitBreaker($rule->circuitBreaker);
            $call = static fn (): mixed => $breaker->call($call);
        }

        if ($rule->retry !== null) {
            $retry = $this->registry->retry($rule->retry);
            $call = static fn (): mixed => $retry->call($call);
        }

        if ($rule->fallbackMethod !== null) {
            $fallback = new FallbackPattern($this->recovery($invocation, $rule), $rule->fallbackOn);
            $call = static fn (): mixed => $fallback->call($call);
        }

        return $call();
    }

    /**
     * The recovery closure the shipped Fallback component invokes: the named method on the bean itself, with
     * the ORIGINAL arguments, plus the caught Throwable when the fallback's last parameter can accept one.
     *
     * `getThis()` is the PROXY, which extends the bean, so calling the fallback through it reaches the
     * subclass's inherited method — and, crucially, does NOT re-enter this interceptor, because only the
     * methods the plan names are overridden and a fallback that carried resilience attributes of its own
     * would have its own row. A fallback calling the guarded method again is therefore a genuine second
     * guarded call, which is what the author asked for.
     *
     * WHETHER TO APPEND THE CAUSE IS READ OFF THE ROW, not re-derived. `ResilienceMethodScanner` proved it
     * while it was proving the recovery's arity — the two are one implementation there, which is why a
     * recovery one parameter too wide is refused at `firefly:cache` instead of raising ArgumentCountError
     * here — and compiled it into `$rule->fallbackAcceptsThrowable`. A row is compiled per CONCRETE class and
     * the proxy this interceptor is handed is that same class's, so there is no runtime widening to discover.
     * This class therefore imports no `Reflection*` at all, like TransactionInterceptor,
     * ObservabilityMethodInterceptor and MethodSecurityInterceptor before it — and
     * `packages/resilience/tests/ReflectionFreeResilienceTest.php` keeps the package's reflection at its one
     * sanctioned site, `ResilienceMethodScanner.php`, as an EXACT set. If a change here makes that test red,
     * the answer is to compile the new fact in the scanner, never to widen the pin.
     */
    private function recovery(MethodInvocation $invocation, ResilienceMethodDescriptor $rule): Closure
    {
        $target = $invocation->getThis();
        $method = (string) $rule->fallbackMethod;
        $arguments = $invocation->getArguments();
        $wantsCause = $rule->fallbackAcceptsThrowable;

        return static function (Throwable $cause) use ($target, $method, $arguments, $wantsCause): mixed {
            $args = $wantsCause ? [...$arguments, $cause] : $arguments;

            /** @var callable $callable */
            $callable = [$target, $method];

            return $callable(...$args);
        };
    }
}
