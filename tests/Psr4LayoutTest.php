<?php

declare(strict_types=1);

/**
 * Every src file must declare the namespace its package prefix and directory imply.
 *
 * This exists because a stray file once landed in the wrong package: a byte-identical copy of
 * packages/openapi/src/Schema/MemberType.php appeared at packages/admin/src/Data/MemberType.php, still
 * declaring `namespace Firefly\OpenApi\Schema`. Composer's PSR-4 autoloader will not find a class there, so
 * nothing broke at runtime and nothing failed in the package's own suite — it surfaced only as a duplicate
 * class in a repo-wide static analysis run, which is a long way from the mistake.
 *
 * A misfiled class is also how a package silently acquires a dependency it never declared, which deptrac
 * cannot see because the file claims to belong to the other layer.
 */
it('declares a namespace matching the package prefix and directory for every src file', function () {
    $mismatches = [];

    foreach (glob(dirname(__DIR__).'/packages/*/composer.json') ?: [] as $composer) {
        $package = dirname($composer);
        /** @var mixed $json */
        $json = json_decode((string) file_get_contents($composer), true);

        $autoload = is_array($json) ? ($json['autoload'] ?? null) : null;
        $declared = is_array($autoload) ? ($autoload['psr-4'] ?? null) : null;

        /** @var array<string, string> $psr4 */
        $psr4 = is_array($declared) ? $declared : [];

        foreach ($psr4 as $prefix => $relative) {
            $root = $package.'/'.rtrim($relative, '/');
            if (! is_dir($root)) {
                continue;
            }

            /** @var iterable<SplFileInfo> $files */
            $files = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
            );

            foreach ($files as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $source = (string) file_get_contents($file->getPathname());
                if (preg_match('/^namespace\s+([^;]+);/m', $source, $matches) !== 1) {
                    continue;
                }

                $declared = trim($matches[1]).'\\';
                $sub = str_replace('/', '\\', dirname(substr($file->getPathname(), strlen($root) + 1)));
                $expected = rtrim(rtrim($prefix, '\\').'\\'.($sub === '.' ? '' : $sub), '\\').'\\';

                if ($declared !== $expected) {
                    $mismatches[] = sprintf('%s declares %s, expected %s', $file->getPathname(), $declared, $expected);
                }
            }
        }
    }

    expect($mismatches)->toBe([]);
});
