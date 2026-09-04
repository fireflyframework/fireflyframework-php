<?php

declare(strict_types=1);

use Firefly\Admin\Data\DataBrowserSettings;
use Firefly\Config\Config;
use Illuminate\Config\Repository;

/** @param array<string, mixed> $data the `firefly.admin.data.*` subtree */
function dataSettings(array $data = [], bool $appDebug = true): DataBrowserSettings
{
    return DataBrowserSettings::fromConfig(new Config(new Repository([
        'app' => ['debug' => $appDebug],
        'firefly' => ['admin' => ['enabled' => true, 'data' => $data]],
    ])));
}

// The whole point of the separate gate: the dashboard follows app.debug, this does not follow anything.
it('is off by default even with app.debug on and the dashboard enabled', function () {
    $settings = dataSettings();

    expect($settings->enabled)->toBeFalse()
        ->and($settings->writable)->toBeFalse()
        ->and($settings->canWrite())->toBeFalse();
});

it('needs both keys before a write is permitted', function () {
    expect(dataSettings(['enabled' => true])->canWrite())->toBeFalse()
        // Arming writes without switching the browser on does nothing at all.
        ->and(dataSettings(['writable' => true])->canWrite())->toBeFalse()
        ->and(dataSettings(['writable' => true])->enabled)->toBeFalse()
        ->and(dataSettings(['enabled' => true, 'writable' => true])->canWrite())->toBeTrue();
});

it('defaults the page sizes and clamps a caller-supplied one', function () {
    $settings = dataSettings(['enabled' => true]);

    expect($settings->pageSize)->toBe(25)
        ->and($settings->maxPageSize)->toBe(200)
        ->and($settings->clampPageSize(null))->toBe(25)
        ->and($settings->clampPageSize(50))->toBe(50)
        ->and($settings->clampPageSize(1_000_000))->toBe(200)
        ->and($settings->clampPageSize(0))->toBe(1)
        ->and($settings->clampPageSize(-9))->toBe(1);
});

it('caps a configured maximum at the hard ceiling, and the default page size at the maximum', function () {
    // An application cannot configure its way to an OOM: one request must never be able to ask for a
    // million rows just because a config key said so.
    expect(dataSettings(['enabled' => true, 'max-page-size' => 50_000])->maxPageSize)->toBe(DataBrowserSettings::PAGE_SIZE_CEILING)
        ->and(dataSettings(['enabled' => true, 'max-page-size' => 0])->maxPageSize)->toBe(1)
        // A default larger than the maximum is incoherent; the maximum wins.
        ->and(dataSettings(['enabled' => true, 'page-size' => 900, 'max-page-size' => 100])->pageSize)->toBe(100);
});

it('parses the exclusion list as case-insensitive csv and refuses those slugs', function () {
    $settings = dataSettings(['enabled' => true, 'exclude' => ' User , AUDIT-LOG ,, ']);

    expect($settings->excluded)->toBe(['user', 'audit-log'])
        ->and($settings->allows('user'))->toBeFalse()
        ->and($settings->allows('audit-log'))->toBeFalse()
        ->and($settings->allows('wallet'))->toBeTrue()
        ->and(dataSettings(['enabled' => true])->allows('anything'))->toBeTrue();
});
