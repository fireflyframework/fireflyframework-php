<?php

declare(strict_types=1);

use Firefly\Admin\AdminSettings;
use Firefly\Admin\Table\TableSettings;
use Firefly\Config\Config;
use Illuminate\Config\Repository;

/** @param array<string,mixed> $values */
function tableConfig(array $values): Config
{
    return new Config(new Repository($values));
}

it('defaults to fifty rows from a closed set of four', function () {
    $settings = TableSettings::fromConfig(tableConfig([]));

    expect($settings->pageSize)->toBe(50)
        ->and($settings->pageSizes)->toBe([25, 50, 100, 200])
        ->and($settings->maxPageSize)->toBe(200)
        ->and($settings->maxHeight)->toBe('68vh')
        ->and($settings->density)->toBe(TableSettings::DENSITY_COMFORTABLE)
        ->and($settings->rememberScroll)->toBeTrue();
});

// ON by default because it restores parity rather than inventing an affordance: before `.tw` had a height
// the page itself was the scrollport and the browser brought the offset back across a reload for free.
it('lets a deployment decline the scroll restore', function () {
    expect(TableSettings::fromConfig(tableConfig([
        'firefly' => ['admin' => ['table' => ['remember-scroll' => false]]],
    ]))->rememberScroll)->toBeFalse();
});

// The offered set is what the rows-per-page control renders, so the configured default must always be IN
// it — otherwise the <select> has no selected option and the control silently resets the size on submit.
it('adds the configured default to the offered set when the set does not contain it', function () {
    $settings = TableSettings::fromConfig(tableConfig([
        'firefly' => ['admin' => ['table' => ['page-size' => 30, 'page-sizes' => '25,50']]],
    ]));

    expect($settings->pageSizes)->toBe([25, 30, 50])->and($settings->pageSize)->toBe(30);
});

it('drops an offered size that is not a positive integer or exceeds the ceiling', function () {
    $settings = TableSettings::fromConfig(tableConfig([
        'firefly' => ['admin' => ['table' => ['page-sizes' => '25, x, -5, 0, 100, 9000', 'max-page-size' => 200]]],
    ]));

    expect($settings->pageSizes)->toBe([25, 50, 100]);
});

it('caps max-page-size at the hard ceiling the data browser already uses', function () {
    $settings = TableSettings::fromConfig(tableConfig([
        'firefly' => ['admin' => ['table' => ['max-page-size' => 1000000]]],
    ]));

    expect($settings->maxPageSize)->toBe(TableSettings::PAGE_SIZE_CEILING);
});

// A size arrives in a URL an operator can hand-edit. The set is CLOSED rather than merely capped, because
// ?size=199 on a 4 000 000-row listing is a request nobody offered and one the page cannot decline halfway.
it('accepts only an offered size from a caller and otherwise uses the default', function (?int $requested, int $expected) {
    expect(TableSettings::fromConfig(tableConfig([]))->clamp($requested))->toBe($expected);
})->with([
    [null, 50],
    [25, 25],
    [200, 200],
    [199, 50],
    [1000000, 50],
    [0, 50],
    [-1, 50],
]);

// max-height is INTERPOLATED INTO THE STYLESHEET. A value that is not a length is not merely wrong, it is
// a CSS injection vector, so it is matched against a pattern and refused rather than escaped.
it('refuses a max-height that is not a length or none', function (string $configured, string $expected) {
    expect(TableSettings::fromConfig(tableConfig([
        'firefly' => ['admin' => ['table' => ['max-height' => $configured]]],
    ]))->maxHeight)->toBe($expected);
})->with([
    ['none', 'none'],
    ['70vh', '70vh'],
    ['480px', '480px'],
    ['32rem', '32rem'],
    ['70', '68vh'],
    ['70vh;}body{display:none', '68vh'],
    ['calc(100vh - 200px)', '68vh'],
    ['', '68vh'],
]);

it('reads a compact density as tighter row padding and anything unrecognised as comfortable', function () {
    $compact = TableSettings::fromConfig(tableConfig([
        'firefly' => ['admin' => ['table' => ['density' => 'COMPACT']]],
    ]));
    $odd = TableSettings::fromConfig(tableConfig([
        'firefly' => ['admin' => ['table' => ['density' => 'roomy']]],
    ]));

    expect($compact->density)->toBe(TableSettings::DENSITY_COMPACT)
        ->and($compact->rowPaddingX())->toBe('10px')
        ->and($compact->rowPaddingY())->toBe('5px')
        ->and($odd->density)->toBe(TableSettings::DENSITY_COMFORTABLE)
        ->and($odd->rowPaddingX())->toBe('14px')
        ->and($odd->rowPaddingY())->toBe('8px');
});

it('hangs off AdminSettings so every view reaches it as $settings->table', function () {
    expect(AdminSettings::fromConfig(tableConfig([
        'firefly' => ['admin' => ['table' => ['page-size' => 100]]],
    ]))->table->pageSize)->toBe(100);
});

/**
 * The data browser's own bounds, folded in BEFORE a request is parsed.
 *
 * Applying them afterwards is what made `firefly.admin.data.page-size` inert: the page stated a size on
 * every call, so the browser's "null means use my default" branch was unreachable and a listing configured
 * for 25 rows served 50. Composed here, the default is the browser's, the offered set is the shared one
 * narrowed to what this listing may serve, and the chrome keys are carried through untouched.
 */
it('composes a second, tighter pair of bounds without touching the chrome keys', function () {
    $settings = TableSettings::fromConfig(tableConfig([
        'firefly' => ['admin' => ['table' => ['density' => 'compact', 'max-height' => '40vh']]],
    ]));

    $bounded = $settings->boundedBy(25, 200);

    expect($bounded->pageSize)->toBe(25)
        ->and($bounded->pageSizes)->toBe([25, 50, 100, 200])
        ->and($bounded->maxPageSize)->toBe(200)
        // A size the composed ceiling refuses falls back to the composed default, not the shared one.
        ->and($bounded->clamp(200))->toBe(200)
        ->and($bounded->clamp(null))->toBe(25)
        ->and($bounded->density)->toBe(TableSettings::DENSITY_COMPACT)
        ->and($bounded->maxHeight)->toBe('40vh');
});

// The tighter of the two ceilings wins and the set is narrowed to it, so the control cannot offer a size
// the listing would refuse to serve. The default is forced in afterwards, exactly as fromConfig() does.
it('narrows the offered set to the tighter ceiling and keeps the new default inside it', function () {
    $bounded = TableSettings::fromConfig(tableConfig([]))->boundedBy(10, 30);

    expect($bounded->pageSizes)->toBe([10, 25])
        ->and($bounded->pageSize)->toBe(10)
        ->and($bounded->maxPageSize)->toBe(30)
        ->and($bounded->clamp(50))->toBe(10);
});

// A default past the ceiling is the ceiling, and neither bound may be talked below one row.
it('keeps a composed default inside its own ceiling', function () {
    $bounded = TableSettings::fromConfig(tableConfig([]))->boundedBy(500, 40);

    expect($bounded->pageSize)->toBe(40)->and($bounded->maxPageSize)->toBe(40)
        ->and(TableSettings::fromConfig(tableConfig([]))->boundedBy(0, 0)->pageSize)->toBe(1);
});

/**
 * WHAT THE ROWS-PER-PAGE CONTROL RENDERS, which is not quite the offered set. `clamp()` only ever returns a
 * member and `boundedBy()` keeps the data browser inside the set too, so this returns the set unchanged for
 * every listing in the tree. It exists for `ListingQuery::sized()`: a query re-stated at the size it was
 * SERVED holds whatever the source served, and a <select> whose current value has no <option> shows the
 * first one — pressing Apply then resizes the table the operator was reading.
 */
it('splices a size it was served but does not offer into the rendered set', function () {
    $settings = TableSettings::fromConfig(tableConfig([]));

    expect($settings->offering(50))->toBe([25, 50, 100, 200])
        ->and($settings->offering(30))->toBe([25, 30, 50, 100, 200])
        ->and($settings->offering(1))->toBe([1, 25, 50, 100, 200])
        ->and($settings->offering(500))->toBe([25, 50, 100, 200, 500]);
});
