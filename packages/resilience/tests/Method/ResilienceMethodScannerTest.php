<?php

declare(strict_types=1);

use Firefly\Resilience\Exception\BulkheadFullException;
use Firefly\Resilience\Method\ResilienceMethodDescriptor;
use Firefly\Resilience\Scanner\ResilienceMethodScanner;
use Firefly\Resilience\Tests\Fixtures\ClassLevelPayments\PaymentGateway as ClassLevelPaymentGateway;
use Firefly\Resilience\Tests\Fixtures\Method\PaymentService;
use Firefly\Resilience\Tests\Fixtures\NarrowedFallback\PaymentGateway as NarrowedPaymentGateway;

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
