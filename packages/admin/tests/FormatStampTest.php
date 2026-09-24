<?php

declare(strict_types=1);

use Firefly\Admin\Format;

/**
 * THE WIDTH OF A RIGID COLUMN IS A CLAIM ABOUT ITS FORMATTER, and this is where the claim is pinned.
 *
 * `TableColumn::stamp()` is sized in CHARACTERS — the kind is rigid precisely because "its content has a
 * known alphabet" — and the alphabet of the When column on /firefly/http is whatever Format::since can
 * return. That is not one shape: four of its arms give an age, six or seven characters wide, and the fifth
 * gives a dated stamp of sixteen. A column sized for the age arms draws the stamp arm with its tail cut
 * off by `table.ftable td{overflow:hidden}`, and no unit test of the page can see that happen — so what is
 * asserted here is the input to the width instead: the longest string the formatter has.
 *
 * `$now` is a fixed epoch rather than `microtime(true)` because these are assertions about LENGTHS, and a
 * length that depends on when the suite ran is not an assertion.
 */
it('gives an age under a day and a sixteen-character stamp past one', function () {
    $now = 1_800_000_000.0;

    expect(Format::since($now, $now))->toBe('just now')
        ->and(Format::since($now - 30, $now))->toBe('30s ago')
        ->and(Format::since($now - 300, $now))->toBe('5m ago')
        ->and(Format::since($now - 7200, $now))->toBe('2h ago')
        // The last age the formatter gives, and the first stamp. 86400 is the boundary the HTTP traffic
        // page crosses whenever the ring — which is cache-backed on purpose, so it outlives the process —
        // still holds a request from yesterday.
        ->and(Format::since($now - 86399, $now))->toBe('23h ago')
        ->and(Format::since($now - 86401, $now))->toBe(date('Y-m-d H:i', (int) ($now - 86401)));
});

it('never emits an age wider than eight characters, nor a stamp wider than sixteen', function () {
    $now = 1_800_000_000.0;
    $ages = [0, 1, 2, 9, 59, 60, 599, 3599, 3600, 35999, 86399];
    $stamps = [86401, 200_000, 9_000_000, 900_000_000];

    foreach ($ages as $secondsAgo) {
        expect(strlen(Format::since($now - $secondsAgo, $now)))->toBeLessThanOrEqual(8);
    }

    // Sixteen is the number `AdminAction::data()` declares the When column from — `ch: 16` — and it is the
    // number that has to move first if an arm ever renders something longer.
    foreach ($stamps as $secondsAgo) {
        expect(strlen(Format::since($now - $secondsAgo, $now)))->toBe(16);
    }
});

/**
 * The instant is the full nineteen characters `TableColumn::stamp()` takes as its DEFAULT width, and it is
 * what a stamp cell carries on its title: an age is lossy in both directions — `2h ago` does not say which
 * two hours, and the dated arm rounds the seconds off — so a reader correlating an exchange against a log
 * line has somewhere to read the whole thing from, exactly as `t-token`, `t-path` and `t-line` do.
 */
it('spells the instant out in full for the title, seconds included', function () {
    $at = 1_800_000_000.0;

    expect(Format::instant($at))->toBe(date('Y-m-d H:i:s', (int) $at))
        ->and(strlen(Format::instant($at)))->toBe(19)
        // The title is strictly more than the cell: the same sixteen characters the dated arm renders,
        // plus the three of the seconds it drops.
        ->and(substr(Format::instant($at), 0, 16))->toBe(Format::since($at, $at + 200_000));
});
