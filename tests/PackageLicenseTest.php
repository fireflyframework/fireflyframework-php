<?php

declare(strict_types=1);

/** @return list<string> absolute paths of every publishable unit dir */
function fireflyUnitDirs(): array
{
    $root = dirname(__DIR__);
    $dirs = glob($root.'/packages/*', GLOB_ONLYDIR) ?: [];
    $dirs[] = $root.'/skeleton';

    return $dirs;
}

it('ships an Apache-2.0 LICENSE in every publishable unit', function () {
    $rootLicense = file_get_contents(dirname(__DIR__).'/LICENSE');

    foreach (fireflyUnitDirs() as $dir) {
        $license = $dir.'/LICENSE';
        expect(is_file($license))->toBeTrue("missing LICENSE in {$dir}")
            ->and(file_get_contents($license))->toBe($rootLicense, "LICENSE mismatch in {$dir}");
    }
});

it('never export-ignores LICENSE or README from the dist', function () {
    foreach (fireflyUnitDirs() as $dir) {
        $ga = $dir.'/.gitattributes';
        if (! is_file($ga)) {
            continue;
        }
        $contents = (string) file_get_contents($ga);
        expect($contents)->not->toMatch('/^\s*\/?LICENSE\s+export-ignore/mi', "LICENSE export-ignored in {$dir}")
            ->and($contents)->not->toMatch('/^\s*\/?README\.md\s+export-ignore/mi', "README export-ignored in {$dir}");
    }
});
