<?php

declare(strict_types=1);

use Firefly\Resilience\Exception\BulkheadFullException;
use Firefly\Resilience\Method\ResilienceMethodDescriptor;
use Firefly\Resilience\Scanner\ResilienceMethodScanner;
use Firefly\Resilience\Tests\Fixtures\CauseSlotFallback\PaymentGateway as CauseSlotPaymentGateway;
use Firefly\Resilience\Tests\Fixtures\ClassLevelPayments\PaymentGateway as ClassLevelPaymentGateway;
use Firefly\Resilience\Tests\Fixtures\Method\PaymentService;
use Firefly\Resilience\Tests\Fixtures\NarrowedFallback\PaymentGateway as NarrowedPaymentGateway;
use Firefly\Resilience\Tests\Fixtures\UnprovableCauseFallback\PaymentGateway as UnprovablePaymentGateway;

/**
 * The PSR-4 root the well-formed fixtures live under. Each offender lives in its OWN directory beside it, so
 * a happy-path scan never walks into a refusal and a refusal test never depends on scan order.
 *
 * @return array<string, string>
 */
function resiliencePsr4(string $dir = 'Method'): array
{
    return ['Firefly\\Resilience\\Tests\\Fixtures\\'.$dir => __DIR__.'/../Fixtures/'.$dir];
}

/**
 * One well-formed directory's rows, indexed the way a reader thinks about them.
 *
 * @return array<string, ResilienceMethodDescriptor> keyed by Class::method
 */
function resilienceRules(string $dir = 'Method'): array
{
    $rules = [];
    foreach ((new ResilienceMethodScanner)->scan(resiliencePsr4($dir)) as $rule) {
        $rules[$rule->key()] = $rule;
    }

    return $rules;
}

it('compiles all six attributes on one method into one descriptor', function (): void {
    $rules = (new ResilienceMethodScanner)->scan(resiliencePsr4());

    $charge = array_values(array_filter($rules, fn (ResilienceMethodDescriptor $d): bool => $d->key() === PaymentService::class.'::charge'))[0];

    expect($charge->bulkhead)->toBe('payments')
        ->and($charge->timeLimiter)->toBe('payments')
        ->and($charge->rateLimiter)->toBe('payments')
        ->and($charge->circuitBreaker)->toBe('payments')
        ->and($charge->retry)->toBe('payments')
        ->and($charge->fallbackMethod)->toBe('chargeUnavailable')
        ->and($charge->fallbackOn)->toBe([Throwable::class]);
});

/*
 | The tenth field is the only one that is not a NAME: whether the parameter in the APPENDED SLOT — the
 | recovery's parameter at the guarded method's parameter count, which is where `[...$arguments, $cause]`
 | really puts the cause — accepts the caught Throwable. The interceptor needs it to decide whether to append
 | the cause, and compiling it here is what keeps that interceptor free of `new ReflectionMethod(...)` on
 | every recovery — the exact-set pin in ReflectionFreeResilienceTest is the other half of the same claim.
 | Both answers are asserted, because a field that is only ever proved true would pass just as well
 | hard-coded — and both are asserted against a recovery whose LAST parameter says the opposite, because the
 | slot and the last parameter coincide only when the recovery is exactly one parameter wider than the
 | guarded method, and reading the last one there was an off-by-one that appended the cause into a slot
 | declared for something else.
 */

it('compiles whether the slot after the guarded arguments accepts the Throwable, both ways', function (): void {
    // `chargeUnavailable(string, int, ?Throwable = null)` for a two-argument call — the cause is appended,
    // so the capacity the arity proof allowed and the call the interceptor makes are the same width.
    expect(resilienceRules()[PaymentService::class.'::charge']->fallbackAcceptsThrowable)->toBeTrue()
        // `chargeUnavailable(string $account)` — nothing in the appended slot, so the row says so and the
        // original arguments are passed unchanged.
        ->and(resilienceRules('NarrowedFallback')[NarrowedPaymentGateway::class.'::charge']->fallbackAcceptsThrowable)->toBeFalse()
        // …and a method with no fallback at all carries the conservative default rather than a stale true.
        ->and(resilienceRules()[PaymentService::class.'::refund']->fallbackAcceptsThrowable)->toBeFalse();
});

it('answers the flag from the appended slot, not from the recovery\'s first or last parameter', function (): void {
    $rules = resilienceRules('CauseSlotFallback');

    // `queued(string $account, ?string $note = null, ?Throwable $cause = null)` for a ONE-argument call: the
    // Throwable is the recovery's LAST parameter and the answer is still FALSE, because the slot the
    // interceptor fills is `$note`. Reading the last parameter answered true here and produced
    // `queued('acct', $cause)` — a TypeError, raised from inside the catch that was absorbing the outage.
    expect($rules[CauseSlotPaymentGateway::class.'::charge']->fallbackAcceptsThrowable)->toBeFalse()
        // `reconcileQueued(Throwable $cause)` for a NO-argument call: the Throwable is the recovery's FIRST
        // parameter and the answer is TRUE, because with nothing to append it after, the slot IS parameter
        // one. Position alone proves neither answer; the slot proves both.
        ->and($rules[CauseSlotPaymentGateway::class.'::reconcile']->fallbackAcceptsThrowable)->toBeTrue();
});

/*
 | …and the other half of a refusal that names a misplaced cause: it must never fire on a signature that
 | works. The position proof abstains wherever reflection would have to guess — a `mixed` argument holds an
 | exception as happily as anything else, a VARIADIC guarded method has no fixed argument count for a
 | position to be measured against, and a union type in the slot is not a single named type — so all three
 | compile, and a later tightening that refuses one of them has to come past this test first.
 */

it('refuses none of the shapes whose cause position reflection cannot settle', function (): void {
    $rules = resilienceRules('UnprovableCauseFallback');

    expect(array_keys($rules))->toBe([
        UnprovablePaymentGateway::class.'::mixedArgument',
        UnprovablePaymentGateway::class.'::variadic',
        UnprovablePaymentGateway::class.'::union',
    ])
        // The union in the appended slot keeps the conservative answer: the cause is not appended, so the
        // recovery is called with the guarded arguments alone and its default fills the rest.
        ->and($rules[UnprovablePaymentGateway::class.'::union']->fallbackAcceptsThrowable)->toBeFalse();
});

it('compiles a method that carries only #[Retry]', function (): void {
    $rules = (new ResilienceMethodScanner)->scan(resiliencePsr4());

    $refund = array_values(array_filter($rules, fn (ResilienceMethodDescriptor $d): bool => $d->key() === PaymentService::class.'::refund'))[0];

    expect($refund->retry)->toBe('payments')->and($refund->circuitBreaker)->toBeNull()->and($refund->fallbackMethod)->toBeNull();
});

it('compiles a row for no method that carries nothing', function (): void {
    $keys = array_map(fn (ResilienceMethodDescriptor $d): string => $d->key(), (new ResilienceMethodScanner)->scan(resiliencePsr4()));

    expect($keys)->toBe([PaymentService::class.'::charge', PaymentService::class.'::refund']);
});

it('renders the compiled rows through toArray()/fromArray() unchanged', function (): void {
    $rules = (new ResilienceMethodScanner)->scan(resiliencePsr4());

    $charge = array_values(array_filter($rules, fn (ResilienceMethodDescriptor $d): bool => $d->key() === PaymentService::class.'::charge'))[0];

    // The row is what var_export writes into proxy-plan.php, so the round trip is the cached boot.
    expect(ResilienceMethodDescriptor::fromArray($charge->toArray()))->toEqual($charge);
});

it('indexes the proxy advice by class and method', function (): void {
    $advice = (new ResilienceMethodScanner)->scanProxyAdvice(resiliencePsr4());

    // The whole row, not one key: this array is exactly what var_export writes into proxy-plan.php, so an
    // added or renamed field has to be looked at rather than slipping past an assertion on a single offset.
    expect(array_keys($advice))->toBe([PaymentService::class])
        ->and(array_keys($advice[PaymentService::class]))->toBe(['charge', 'refund'])
        ->and($advice[PaymentService::class]['refund'])->toBe([
            'class' => PaymentService::class,
            'method' => 'refund',
            'bulkhead' => null,
            'timeLimiter' => null,
            'rateLimiter' => null,
            'circuitBreaker' => null,
            'retry' => 'payments',
            'fallbackMethod' => null,
            'fallbackOn' => [],
            'fallbackAcceptsThrowable' => false,
        ]);
});

/*
 | The class-level rule is the parity claim five of the six attributes make in their own docblocks ("a
 | class-level attribute applies to every public method, a method-level one replaces it"), and it is the
 | piece most able to regress in silence: it is one `??` per pattern, and `?:` in its place would swallow a
 | method-level instance whose name happened to be falsy, while reading the class attributes inside the
 | method loop would keep every other test in this file green at the cost of a reflection call per method.
 */

it('applies a class-level guard to every public method and lets a method-level one replace its own kind', function (): void {
    $rules = resilienceRules('ClassLevelPayments');

    expect($rules[ClassLevelPaymentGateway::class.'::charge']->retry)->toBe('payments')
        ->and($rules[ClassLevelPaymentGateway::class.'::charge']->circuitBreaker)->toBe('payments')
        // REPLACED per KIND, never wholesale: `refund()` names its own retry instance and keeps the class's
        // breaker, because the five patterns are resolved independently of one another.
        ->and($rules[ClassLevelPaymentGateway::class.'::refund']->retry)->toBe('refunds')
        ->and($rules[ClassLevelPaymentGateway::class.'::refund']->circuitBreaker)->toBe('payments')
        ->and($rules[ClassLevelPaymentGateway::class.'::charge']->bulkhead)->toBeNull()
        ->and($rules[ClassLevelPaymentGateway::class.'::charge']->fallbackMethod)->toBeNull();
});

it('never fans a class-level guard onto a static or a magic method', function (): void {
    // Neither can be intercepted: a static call has no instance to wrap, and the `__firefly*` members the
    // generated proxy declares make the magic methods its own. The FAN-OUT is what is skipped here, and
    // silently, because the author wrote one attribute about the class rather than one about `__invoke()`.
    // An attribute written BY HAND on either shape is refused — see ResilienceMethodScannerRefusalTest.
    expect(resilienceRules('ClassLevelPayments'))
        ->not->toHaveKey(ClassLevelPaymentGateway::class.'::reconcileAll')
        ->not->toHaveKey(ClassLevelPaymentGateway::class.'::__invoke');
});

it('carries a narrowed #[Fallback(on:)] list into the row verbatim', function (): void {
    // The default is `[Throwable::class]` — everything — so a row that only ever holds the default proves
    // nothing about the list the author wrote, which is their statement that a programming error keeps
    // propagating while a saturated pool is absorbed.
    expect(resilienceRules('NarrowedFallback')[NarrowedPaymentGateway::class.'::charge']->fallbackOn)
        ->toBe([BulkheadFullException::class, RuntimeException::class]);
});
