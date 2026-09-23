<?php

declare(strict_types=1);

use Firefly\Resilience\Method\ResilienceMethodDescriptor;

/*
 | The row is what `var_export` writes into `proxy-plan.php` and `fromArray()` is what the generated proxy
 | calls to read it back, so every optional key's default is a decision about a plan compiled by an OLDER
 | version of the framework: "must still load" is the rule the whole family of descriptors follows. For
 | `fallbackOn` the obvious default is the wrong one, because for that field the empty list is not an
 | absence — it is the statement "recover NOTHING", which `Firefly\Resilience\Fallback::matches()` honours by
 | answering false for every throwable there is.
 */

it('degrades a legacy row that names a recovery to the attribute\'s own default, not to "recover nothing"', function (): void {
    // A plan compiled before `fallbackOn` existed: the key is absent, the recovery is not. Defaulting to []
    // would load a fallback that composes, runs and then never fires — the silent no-op the scan refuses to
    // compile in the first place — so such a row degrades to the attribute's default instead.
    $legacy = ResilienceMethodDescriptor::fromArray([
        'class' => 'App\\Gateways\\PaymentGateway',
        'method' => 'charge',
        'retry' => 'payments',
        'fallbackMethod' => 'queued',
    ]);

    expect($legacy->fallbackOn)->toBe([Throwable::class])
        ->and($legacy->fallbackMethod)->toBe('queued');
});

it('leaves a legacy row with no recovery holding the empty list it always had', function (): void {
    // Nothing reads `fallbackOn` without a `fallbackMethod`, so the row keeps the empty list rather than
    // gaining a Throwable that means nothing — and `toArray()`/`fromArray()` stay a round trip for it.
    $legacy = ResilienceMethodDescriptor::fromArray([
        'class' => 'App\\Gateways\\PaymentGateway',
        'method' => 'refund',
        'retry' => 'payments',
    ]);

    expect($legacy->fallbackOn)->toBe([])
        ->and(ResilienceMethodDescriptor::fromArray($legacy->toArray()))->toEqual($legacy);
});

it('never rewrites a list the plan really carries', function (): void {
    // The default is reached only through the absent key: a row that narrows `on:` keeps exactly what the
    // scan compiled, which is the author's statement about what may be recovered.
    $row = ResilienceMethodDescriptor::fromArray([
        'class' => 'App\\Gateways\\PaymentGateway',
        'method' => 'charge',
        'fallbackMethod' => 'queued',
        'fallbackOn' => [RuntimeException::class],
    ]);

    expect($row->fallbackOn)->toBe([RuntimeException::class]);
});
