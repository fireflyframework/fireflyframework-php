<?php

declare(strict_types=1);

use Firefly\Installer\CapabilityCatalog;

it('includes every installer capability in the single root library', function () {
    /** @var array{replace: array<string, string>} $root */
    $root = json_decode((string) file_get_contents(dirname(__DIR__).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach (CapabilityCatalog::all() as $capability) {
        expect($root['replace'][$capability->package] ?? null)->toBe('self.version');
    }
});
