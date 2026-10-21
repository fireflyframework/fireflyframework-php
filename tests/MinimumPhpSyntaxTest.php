<?php

declare(strict_types=1);

/**
 * The runtime floor is PHP 8.5.12, the latest release that contains the
 * October 2026 fixes for CVE-2026-109618 and CVE-2026-109619. Every manifest
 * has to say so. A floating `^8.3` would still install on an unpatched
 * interpreter.
 */
it('requires the patched PHP 8.5.12 release in every composer manifest', function () {
    $root = dirname(__DIR__);
    $files = array_merge(
        [$root.'/composer.json', $root.'/skeleton/composer.json'],
        glob($root.'/packages/*/composer.json') ?: [],
        glob($root.'/samples/*/composer.json') ?: [],
    );

    $offenders = [];

    foreach ($files as $file) {
        /** @var array{require?: array<string, string>} $json */
        $json = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $php = $json['require']['php'] ?? null;

        if ($php !== '^8.5.12') {
            $offenders[] = str_replace($root.'/', '', $file).' requires '.var_export($php, true);
        }
    }

    expect($offenders)->toBe([]);
});
