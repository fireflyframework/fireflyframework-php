<?php

declare(strict_types=1);

/**
 * Every configuration key the framework READS must appear in the reference an application receives.
 *
 * skeleton/config/firefly.php is not a config file in the ordinary sense — almost all of it is commented
 * out at its own default — it is the DOCUMENTATION, shipped where people will actually find it. That makes
 * it exactly the kind of file that rots: a key is added to a settings class during a feature, the feature
 * ships, and the only place anybody would have learned about the key never mentions it. Three had already
 * drifted out when this was written (`firefly.management.server.address` — half of the management-port
 * feature — and two OpenAPI Info members), and nothing would ever have said so.
 *
 * THE MATCH IS ON THE LEAF, deliberately loosely. The reference is a nested PHP array, so a dotted key
 * never appears literally in it, and reconstructing the paths would mean evaluating a file that is 90%
 * comments. Checking that the last segment appears as a quoted array key is a weaker assertion that still
 * catches the thing worth catching — a key nobody wrote anything about — while never failing for a key that
 * is documented under a nesting this test cannot see.
 */
it('documents every configuration key the framework reads', function () {
    $root = dirname(__DIR__);
    $reference = (string) file_get_contents($root.'/skeleton/config/firefly.php');

    $keys = [];
    foreach (glob($root.'/packages/*/src') ?: [] as $source) {
        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            // Only keys read through the Config PORT. A bare `firefly.*` string elsewhere is as likely to be
            // a route name or a container flag, and demanding a config entry for those would make this test
            // noise rather than signal.
            preg_match_all(
                "/(?:bool|string|int|array|get|has)\(\s*'(firefly\.[a-z0-9.\-]+)'/",
                (string) file_get_contents($file->getPathname()),
                $matches,
            );

            foreach ($matches[1] as $key) {
                $keys[$key] = true;
            }
        }
    }

    expect($keys)->not->toBeEmpty();

    $undocumented = [];
    foreach (array_keys($keys) as $key) {
        $leaf = substr($key, (int) strrpos($key, '.') + 1);

        if (! str_contains($reference, "'".$leaf."'")) {
            $undocumented[] = $key;
        }
    }

    sort($undocumented);

    expect($undocumented)->toBe([]);
});

/**
 * The reference has to be a PHP file that parses.
 *
 * Checked with `php -l` rather than by requiring it: the file calls `app_path()`, so evaluating it needs a
 * booted Laravel application, and this suite runs against a bare container. The syntax is the half that can
 * rot from an edit here — a stray quote inside one of the long commented blocks — and it is the half a
 * linter answers exactly.
 */
it('ships a reference that parses', function () {
    $file = dirname(__DIR__).'/skeleton/config/firefly.php';

    exec(sprintf('%s -l %s 2>&1', escapeshellarg(PHP_BINARY), escapeshellarg($file)), $output, $status);

    expect($status)->toBe(0, implode("\n", $output));
});
