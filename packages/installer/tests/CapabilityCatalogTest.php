<?php

declare(strict_types=1);

use Firefly\Installer\Capability;
use Firefly\Installer\CapabilityCatalog;

/**
 * The anti-rot guard for the hand-owned capability map.
 *
 * firefly/installer is a GLOBAL install with no monorepo on disk and no firefly runtime dependency, so it
 * cannot enumerate packages/* when it runs. The enumeration therefore happens HERE, where the monorepo
 * exists: every firefly/* package in packages/ must be either a capability or an explicitly-justified core
 * package, so adding a package to the family fails this test until someone decides which it is.
 *
 * @return list<string> every `name` in packages/ * /composer.json
 */
function familyPackages(): array
{
    $root = dirname(__DIR__, 3).'/packages'; // tests -> installer -> packages -> root
    $names = [];
    foreach ((array) glob($root.'/*/composer.json') as $manifest) {
        if (! is_string($manifest)) {
            continue;
        }
        $decoded = json_decode((string) file_get_contents($manifest), true);
        if (is_array($decoded) && isset($decoded['name']) && is_string($decoded['name'])) {
            $names[] = $decoded['name'];
        }
    }
    sort($names);

    return $names;
}

it('accounts for every firefly package in the monorepo', function () {
    $family = familyPackages();
    expect($family)->not->toBeEmpty();

    $known = array_merge(
        array_map(static fn (Capability $c): string => $c->package, array_values(CapabilityCatalog::all())),
        array_keys(CapabilityCatalog::corePackages()),
    );

    $unaccounted = array_values(array_diff($family, $known));

    expect($unaccounted)->toBe([], sprintf(
        'These packages exist in packages/ but are neither a `firefly new --with=` capability nor listed in '
        .'CapabilityCatalog::corePackages(): %s. Decide which they are — a capability the picker offers, or '
        .'framework plumbing with a stated reason.',
        implode(', ', $unaccounted),
    ));
});

it('never offers a capability whose package does not exist', function () {
    $family = familyPackages();

    $missing = array_values(array_filter(
        array_map(static fn (Capability $c): string => $c->package, array_values(CapabilityCatalog::all())),
        static fn (string $package): bool => ! in_array($package, $family, true),
    ));

    // toContain() is variadic in Pest, so a "message" argument would silently become a second needle —
    // asserting the family contains a sentence. Diffing the two lists says the same thing and cannot lie.
    expect($missing)->toBe([]);
});

it('keys every capability by its own id', function () {
    foreach (CapabilityCatalog::all() as $id => $capability) {
        expect($capability->id)->toBe($id);
    }
});

it('resolves an implied port before its adapter', function () {
    $ids = array_map(static fn (Capability $c): string => $c->id, CapabilityCatalog::resolve(['scheduling-postgres']));

    expect($ids)->toBe(['scheduling', 'scheduling-postgres']);
});

it('deduplicates a capability requested twice, directly and transitively', function () {
    $ids = array_map(static fn (Capability $c): string => $c->id, CapabilityCatalog::resolve(['eda', 'eda-kafka', 'eda']));

    expect($ids)->toBe(['eda', 'eda-kafka']);
});

it('rejects an unknown id with the list of real ones', function () {
    expect(fn () => CapabilityCatalog::resolve(['nope']))
        ->toThrow(InvalidArgumentException::class, 'Unknown capability "nope"');
});

it('leaves infrastructure adapters out of --full', function () {
    $ids = array_map(static fn (Capability $c): string => $c->id, CapabilityCatalog::full());

    foreach (CapabilityCatalog::all() as $capability) {
        expect(in_array($capability->id, $ids, true))->toBe(! $capability->adapter);
    }
    expect($ids)->not->toBeEmpty();
});

it('marks only the test kit as a dev dependency', function () {
    $dev = array_values(array_map(
        static fn (Capability $c): string => $c->package,
        array_filter(CapabilityCatalog::all(), static fn (Capability $c): bool => $c->dev),
    ));

    expect($dev)->toBe(['firefly/testing']);
});
