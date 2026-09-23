<?php

declare(strict_types=1);

namespace Firefly\Resilience\Method;

use Attribute;
use Throwable;

/**
 * Resilience4j's `fallbackMethod`, as its own attribute: when the guarded call finally fails — after the
 * retry gave up, after the breaker refused, after the bulkhead was full — `method` on the SAME class is
 * called instead, with the original arguments and, APPENDED AFTER THEM, the Throwable when the parameter in
 * that slot accepts one. The cause goes LAST, which is Resilience4j's order rather than Spring `@Recover`'s
 * (the cause first): a recovery that declares it first is handed a guarded argument there instead, so the
 * scan refuses that signature by name rather than letting it fatal inside the catch.
 *
 * It is the OUTERMOST link of the resilience composition, which is the only place it can be: a fallback
 * that sat inside Retry would be invoked on every failed attempt and the retry would then "succeed" on the
 * fallback's value, so nothing would ever be retried. See ResilienceMethodInterceptor for the whole order.
 *
 * `on` narrows which throwables are recovered; anything else propagates untouched, so a fallback cannot
 * accidentally swallow a programming error. The list must not be EMPTY — `$cause instanceof` no entry at all
 * is false for everything, so an empty list is a fallback that never fires — and every entry must name a
 * class or interface that LOADS and that IS a Throwable. The scan proves all three: `$cause instanceof` a
 * class nothing declares is false without autoloading and without erroring, so a typo in this list is a
 * fallback that silently never fires, exactly as an empty list is.
 *
 * A NAMED METHOD THAT DOES NOT EXIST, THAT CANNOT BE CALLED, OR THAT PUTS THE CAUSE IN THE WRONG SLOT, IS A
 * ConfigurationException AT SCAN TIME — never a runtime surprise inside a `catch`. The whole point of a
 * fallback is to be the thing that works when nothing else does; discovering at 3am that it was misspelled,
 * inside the handler for the outage it was supposed to absorb, is the single worst moment to find out. The
 * scan checks that the method is not the guarded method itself (which recovers by recursing until the stack
 * ends), that it EXISTS, that it is PUBLIC — the interceptor calls it on the bean from outside, so a
 * `protected` one fatals exactly where a missing one would — that no parameter BEFORE the appended slot is
 * typed as a Throwable (the `@Recover` order, which would be handed a guarded argument and fatal with a
 * TypeError), and that its required-parameter count can be satisfied by the guarded method's arguments,
 * counting the appended Throwable ONLY when the parameter in that slot accepts one, because that is the
 * single condition under which the interceptor appends it. The scan answers that same question once and
 * compiles the answer into the row, so the two halves cannot drift: the slot the scan proves is the slot the
 * call really fills.
 *
 * WHAT IT DOES NOT PROVE is a positional TYPE mismatch between two ordinary parameters — a recovery taking
 * an `int` where the guarded method takes a `string`. That one is not decidable by reflection alone: a
 * declared class type can be satisfied by a subclass the scan never sees, so only the Throwable slot, whose
 * type PHP refuses to let an unrelated class implement, is provable. The rest is left to PHP, like a union
 * type and a variadic.
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
