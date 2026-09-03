<?php

declare(strict_types=1);

it('is a code-less metapackage requiring the runtime family plus the cli, but not testing', function () {
    /** @var array<string,mixed> $json */
    $json = json_decode((string) file_get_contents(dirname(__DIR__).'/composer.json'), true);

    expect($json['type'])->toBe('metapackage')
        ->and($json)->not->toHaveKey('autoload')
        ->and($json)->not->toHaveKey('version')
        ->and($json['require'])->toHaveKey('firefly/web')
        ->and($json['require'])->toHaveKey('firefly/security')
        ->and($json['require'])->toHaveKey('firefly/kernel')
        ->and($json['require'])->toHaveKey('firefly/data')
        ->and($json['require'])->not->toHaveKey('firefly/testing');
});

// firefly/cli used to be excluded here deliberately ("the dev console does not belong in a runtime BOM").
// That was wrong in a way nothing caught: firefly/cli shipped the ONLY loader for every Category-B manifest
// (routes, handlers, listeners, scheduled tasks, constraints, method-security rules and #[ConfigProperties]),
// so `composer require firefly/firefly` produced an app whose routes 404'd and whose #[PreAuthorize] rules
// were silently unenforced. Each capability package now resolves its own manifest (see AppScan), which fixes
// the runtime hole — but the CLI still owns `firefly:cache`, and a production app that never compiles its
// manifests pays a full reflection scan on every boot. It belongs in the BOM.
it('requires firefly/cli so an app installing the BOM can compile its manifests', function () {
    /** @var array<string,mixed> $json */
    $json = json_decode((string) file_get_contents(dirname(__DIR__).'/composer.json'), true);

    expect($json['require'])->toHaveKey('firefly/cli');
});
