<?php

declare(strict_types=1);

use Firefly\Observability\Method\ObservabilityMethodDescriptor;
use Firefly\Observability\Scanner\ObservabilityMethodScanner;
use Firefly\Observability\Tests\Fixtures\Method\ObservedService;
use Firefly\Observability\Tests\Fixtures\Method\TimedService;

/**
 * The PSR-4 root the well-formed fixtures live under. The three offenders each live in their OWN directory
 * beside it, so a happy-path scan never walks into a refusal.
 *
 * @return array<string, string>
 */
function observabilityPsr4(string $sub = ''): array
{
    return ['Firefly\\Observability\\Tests\\Fixtures\\Method'.($sub === '' ? '' : '\\'.$sub) => __DIR__.'/../Fixtures/Method'.($sub === '' ? '' : '/'.$sub)];
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

it('round-trips a descriptor through toArray/fromArray', function (): void {
    $descriptor = new ObservabilityMethodDescriptor('C', 'm', ['name' => 'n', 'tags' => [], 'description' => '', 'longTask' => false], null, null);

    expect(ObservabilityMethodDescriptor::fromArray($descriptor->toArray()))->toEqual($descriptor);
});
