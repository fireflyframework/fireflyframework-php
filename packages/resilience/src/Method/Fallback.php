<?php

declare(strict_types=1);

namespace Firefly\Resilience\Method;

use Attribute;
use Throwable;

/**
 * Resilience4j's `fallbackMethod`, as its own attribute: when the guarded call finally fails — after the
 * retry gave up, after the breaker refused, after the bulkhead was full — `method` on the SAME class is
 * called instead, with the original arguments and, if its last parameter accepts one, the Throwable.
 *
 * It is the OUTERMOST link of the resilience composition, which is the only place it can be: a fallback
 * that sat inside Retry would be invoked on every failed attempt and the retry would then "succeed" on the
 * fallback's value, so nothing would ever be retried. See ResilienceMethodInterceptor for the whole order.
 *
 * `on` narrows which throwables are recovered; anything else propagates untouched, so a fallback cannot
 * accidentally swallow a programming error. Every entry must name a class or interface that LOADS and that
 * IS a Throwable, and the scan proves both — `$cause instanceof` a class nothing declares is false without
 * autoloading and without erroring, so a typo in this list is a fallback that silently never fires.
 *
 * A NAMED METHOD THAT DOES NOT EXIST, OR CANNOT RECEIVE THE CALL, IS A ConfigurationException AT SCAN TIME
 * — never a runtime surprise inside a `catch`. The whole point of a fallback is to be the thing that works
 * when nothing else does; discovering at 3am that it was misspelled, inside the handler for the outage it
 * was supposed to absorb, is the single worst moment to find out. The scan checks that the method is not the
 * guarded method itself (which recovers by recursing until the stack ends), that it EXISTS, that it is
 * PUBLIC — the interceptor calls it on the bean from outside, so a `protected` one fatals exactly where a
 * missing one would — and that its required-parameter count can be satisfied by the guarded method's
 * arguments, counting the trailing Throwable ONLY when the recovery's own last parameter accepts one,
 * because that is the single condition under which the interceptor appends it. The scan answers that same
 * question once and compiles the answer into the row, so the two halves cannot drift: the arity the scan
 * proves is the arity the call really makes.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class Fallback
{
    /** @param list<class-string<Throwable>> $on */
    public function __construct(
        public string $method,
        public array $on = [Throwable::class],
    ) {}
}
