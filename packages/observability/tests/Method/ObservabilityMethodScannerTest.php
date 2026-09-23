<?php

declare(strict_types=1);

use Firefly\Observability\Method\ObservabilityMethodDescriptor;
use Firefly\Observability\Scanner\ObservabilityMethodScanner;
use Firefly\Observability\Tests\Fixtures\Method\ClassLevelService;
use Firefly\Observability\Tests\Fixtures\Method\ObservedService;
use Firefly\Observability\Tests\Fixtures\Method\TimedService;

/**
 * The PSR-4 root the well-formed fixtures live under. The offenders each live in their OWN directory beside
 * it, so a happy-path scan never walks into a refusal.
 *
 * @return array<string, string>
 */
function observabilityPsr4(string $sub = ''): array
{
    return ['Firefly\\Observability\\Tests\\Fixtures\\Method'.($sub === '' ? '' : '\\'.$sub) => __DIR__.'/../Fixtures/Method'.($sub === '' ? '' : '/'.$sub)];
}

/**
 * The well-formed scan, indexed the way a reader thinks about it.
 *
 * @return array<string, ObservabilityMethodDescriptor> keyed by Class::method
 */
function observabilityRules(): array
{
    $rules = [];
    foreach ((new ObservabilityMethodScanner)->scan(observabilityPsr4()) as $rule) {
        $rules[$rule->key()] = $rule;
    }

    return $rules;
}

it('compiles a #[Timed] method into a descriptor row', function (): void {
    $rules = (new ObservabilityMethodScanner)->scan(observabilityPsr4());

    $place = array_values(array_filter($rules, fn (ObservabilityMethodDescriptor $d): bool => $d->key() === TimedService::class.'::place'))[0];

    expect($place->timed)->toBe([
        'name' => 'orders.place',
        'tags' => ['tier' => 'gold'],
        'description' => 'Places an order.',
        'longTask' => false,
    ])->and($place->counted)->toBeNull()->and($place->observed)->toBeNull();
});

it('compiles #[Timed] and #[Counted] on the same method into one row', function (): void {
    $rules = (new ObservabilityMethodScanner)->scan(observabilityPsr4());

    $import = array_values(array_filter($rules, fn (ObservabilityMethodDescriptor $d): bool => $d->key() === TimedService::class.'::importAll'))[0];

    // The whole row, not just the flag: a #[Timed] that named no meter compiles an EMPTY name, which is what
    // tells the interceptor to fall back to `firefly.observability.method.timed.name`.
    expect($import->timed)->toBe(['name' => '', 'tags' => [], 'description' => '', 'longTask' => true])
        ->and($import->counted)->toBe(['name' => 'orders.imported', 'tags' => [], 'failuresOnly' => true]);
});

it('compiles #[Observed] with its contextual name and key values', function (): void {
    $rules = (new ObservabilityMethodScanner)->scan(observabilityPsr4());

    $ship = array_values(array_filter($rules, fn (ObservabilityMethodDescriptor $d): bool => $d->key() === ObservedService::class.'::ship'))[0];

    expect($ship->observed)->toBe(['name' => 'orders.ship', 'contextualName' => 'ship order', 'tags' => ['carrier' => 'dhl']]);
});

it('leaves an unannotated method out of the scan entirely', function (): void {
    $keys = array_map(fn (ObservabilityMethodDescriptor $d): string => $d->key(), (new ObservabilityMethodScanner)->scan(observabilityPsr4()));

    expect($keys)->not->toContain(TimedService::class.'::untouched');
});

/*
 | The class-level rule is this task's Micrometer-parity claim and the one the interceptor's semantics rest
 | on: a class-level attribute applies to every public method, a method-level one of the SAME KIND replaces it
 | for that method, and the replacement is total rather than a merge. It is also one line of scanner logic
 | (`?? $classTimed`), so it is the piece most able to regress in silence — `?:` instead of `??` would swallow
 | a method-level meter that named no tags, and reading the class attributes inside the method loop would keep
 | every test below green while costing a reflection call per method.
 */

it('applies a class-level #[Timed] to every public method and lets a method-level one replace it whole', function (): void {
    $rules = observabilityRules();

    expect($rules[ClassLevelService::class.'::inherited']->timed)
        ->toBe(['name' => 'orders.svc', 'tags' => ['scope' => 'class'], 'description' => 'Every order operation.', 'longTask' => false])
        // REPLACED, not merged: neither the class meter's name nor its tags nor its description leak onto a
        // method that named its own.
        ->and($rules[ClassLevelService::class.'::overridden']->timed)
        ->toBe(['name' => 'orders.method', 'tags' => [], 'description' => '', 'longTask' => false])
        ->and($rules[ClassLevelService::class.'::inherited']->counted)->toBeNull()
        ->and($rules[ClassLevelService::class.'::inherited']->observed)->toBeNull();
});

it('resolves the three kinds independently, so a method-level #[Counted] keeps the inherited timer beside it', function (): void {
    $rules = observabilityRules();

    expect($rules[ClassLevelService::class.'::alsoCounted']->timed)
        ->toBe(['name' => 'orders.svc', 'tags' => ['scope' => 'class'], 'description' => 'Every order operation.', 'longTask' => false])
        ->and($rules[ClassLevelService::class.'::alsoCounted']->counted)
        ->toBe(['name' => 'orders.counted', 'tags' => [], 'failuresOnly' => false]);
});

it('never fans a class-level attribute onto a static or a magic method', function (): void {
    // Neither can be intercepted: a static call has no instance to wrap, and the `__`-prefixed methods are the
    // ones the proxy machinery itself uses. The skip filter runs BEFORE the attributes are read, so the
    // class-level rule cannot reach them by the back door.
    expect(observabilityRules())
        ->not->toHaveKey(ClassLevelService::class.'::skipped')
        ->not->toHaveKey(ClassLevelService::class.'::__invoke');
});

it('round-trips a descriptor through toArray/fromArray', function (): void {
    $descriptor = new ObservabilityMethodDescriptor('C', 'm', ['name' => 'n', 'tags' => [], 'description' => '', 'longTask' => false], null, null);

    expect(ObservabilityMethodDescriptor::fromArray($descriptor->toArray()))->toEqual($descriptor);
});
