<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Resilience\Method\ResilienceMethodDescriptor;
use Firefly\Resilience\Scanner\ResilienceMethodScanner;
use Firefly\Resilience\Tests\Fixtures\Method\PaymentService;

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

it('refuses a #[Fallback] naming a method the class does not have', function (): void {
    (new ResilienceMethodScanner)->scan(resiliencePsr4('MissingFallback'));
})->throws(ConfigurationException::class, 'names fallback method');

it('refuses a #[Fallback] whose signature cannot receive the guarded call', function (): void {
    (new ResilienceMethodScanner)->scan(resiliencePsr4('IncompatibleFallback'));
})->throws(ConfigurationException::class, 'cannot receive the guarded call');

it('refuses a #[Fallback] with nothing to fall back from', function (): void {
    (new ResilienceMethodScanner)->scan(resiliencePsr4('LonelyFallback'));
})->throws(ConfigurationException::class, 'carries no other resilience attribute');

it('refuses resilience attributes on an unstereotyped class', function (): void {
    (new ResilienceMethodScanner)->scan(resiliencePsr4('UnstereotypedPayments'));
})->throws(ConfigurationException::class, 'carries no #[Component]-family stereotype');

it('refuses resilience attributes on a final class', function (): void {
    (new ResilienceMethodScanner)->scan(resiliencePsr4('FinalPayments'));
})->throws(ConfigurationException::class, 'is final and a proxy must extend it');
