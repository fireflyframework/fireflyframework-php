<?php

declare(strict_types=1);

it('ships a house-style README in every package', function () {
    foreach (glob(dirname(__DIR__).'/packages/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $readme = $dir.'/README.md';
        expect(is_file($readme))->toBeTrue('missing README in '.$dir);
        $name = basename($dir);
        $contents = (string) file_get_contents($readme);
        expect($contents)->toContain("# firefly/{$name}")
            ->and($contents)->toContain('Apache-2.0 © Firefly Software Solutions Inc.');
    }
});
