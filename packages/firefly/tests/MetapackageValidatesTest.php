<?php

declare(strict_types=1);

it('is a code-less metapackage requiring the runtime family but not cli/testing', function () {
    /** @var array<string,mixed> $json */
    $json = json_decode((string) file_get_contents(dirname(__DIR__).'/composer.json'), true);

    expect($json['type'])->toBe('metapackage')
        ->and($json)->not->toHaveKey('autoload')
        ->and($json)->not->toHaveKey('version')
        ->and($json['require'])->toHaveKey('firefly/web')
        ->and($json['require'])->toHaveKey('firefly/security')
        ->and($json['require'])->toHaveKey('firefly/kernel')
        ->and($json['require'])->toHaveKey('firefly/data')
        ->and($json['require'])->not->toHaveKey('firefly/cli')
        ->and($json['require'])->not->toHaveKey('firefly/testing');
});
