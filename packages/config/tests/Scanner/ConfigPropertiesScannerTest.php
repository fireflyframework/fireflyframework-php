<?php

declare(strict_types=1);

use Firefly\Config\Scanner\ConfigPropertiesDescriptor;
use Firefly\Config\Scanner\ConfigPropertiesScanner;
use Firefly\Config\Tests\Fixtures\AuditProperties;
use Firefly\Config\Tests\Fixtures\DatabaseProperties;
use Firefly\Config\Tests\Fixtures\MailProperties;
use Firefly\Config\Tests\Fixtures\Pool;

it('discovers #[ConfigProperties] classes and their prefixes', function () {
    $descriptors = (new ConfigPropertiesScanner)->scan([
        'Firefly\\Config\\Tests\\Fixtures\\' => __DIR__.'/../Fixtures',
    ]);

    $byClass = [];
    foreach ($descriptors as $d) {
        $byClass[$d->class] = $d->prefix;
    }

    expect($byClass[MailProperties::class])->toBe('mail')
        ->and($byClass[DatabaseProperties::class])->toBe('database')
        // Pool has no #[ConfigProperties] — not discovered:
        ->and($byClass)->not->toHaveKey(Pool::class);
});

it('round-trips a descriptor', function () {
    $d = new ConfigPropertiesDescriptor(MailProperties::class, 'mail');
    expect(ConfigPropertiesDescriptor::fromArray($d->toArray()))->toEqual($d);
});

/**
 * #[Profile] used to be a decoration and nothing more: the attribute shipped, the docs described it,
 * and `grep -rn 'Profile::class' packages/<any>/src` returned NOTHING — no scanner recorded it and no
 * condition could act on it, so a bean marked #[Profile('prod')] was registered in every profile,
 * including the ones it was explicitly excluded from. The scanner is the first half of the fix: the
 * requirement is read ONCE here, at scan time, and travels in the compiled manifest, so no
 * reflection is needed at boot to know that a DTO is profile-gated.
 */
it('records a #[Profile] requirement on the descriptor it scans', function () {
    $descriptors = (new ConfigPropertiesScanner)->scan([
        'Firefly\\Config\\Tests\\Fixtures\\' => __DIR__.'/../Fixtures',
    ]);

    $byClass = [];
    foreach ($descriptors as $d) {
        $byClass[$d->class] = $d->profiles;
    }

    expect($byClass[AuditProperties::class])->toBe(['prod', 'staging'])
        // An unannotated DTO carries no requirement at all — never a sentinel, never null:
        ->and($byClass[MailProperties::class])->toBe([]);
});

it('round-trips a profile-gated descriptor', function () {
    $d = new ConfigPropertiesDescriptor(AuditProperties::class, 'audit', ['prod', 'staging']);

    expect(ConfigPropertiesDescriptor::fromArray($d->toArray()))->toEqual($d);
});

/**
 * A compiled config-properties.php written by an older firefly:cache (or by an older release of
 * firefly/cli, which is a separate package on its own release cadence) has rows with only `class`
 * and `prefix`. Those must keep loading as "no profile requirement" rather than blowing up on a
 * missing array key, so an upgrade never has to be sequenced with a cache rebuild.
 */
it('loads a legacy descriptor row that predates profile recording', function () {
    $d = ConfigPropertiesDescriptor::fromArray(['class' => MailProperties::class, 'prefix' => 'mail']);

    expect($d->profiles)->toBe([]);
});

/**
 * Conversely, a descriptor with NO requirement must serialize to exactly the two keys it always
 * did. That keeps the emitted artifact byte-identical for the overwhelmingly common case, so this
 * change cannot show up as a spurious diff in a committed cache file or in firefly/cli's fixtures.
 */
it('keeps the compiled row byte-identical when there is no profile requirement', function () {
    expect((new ConfigPropertiesDescriptor(MailProperties::class, 'mail'))->toArray())
        ->toBe(['class' => MailProperties::class, 'prefix' => 'mail']);
});
