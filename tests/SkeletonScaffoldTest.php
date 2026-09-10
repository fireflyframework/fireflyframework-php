<?php

declare(strict_types=1);

/**
 * What `composer create-project firefly/skeleton` actually hands a developer.
 *
 * The skeleton is a PROJECT TEMPLATE, not a library, and the difference matters for `.gitattributes`:
 * `export-ignore` is how a library keeps its own tests out of consumers' vendor directories, and Composer
 * honours it when it exports a path repository into the new project. Applied to a template it deletes the
 * very files the template exists to give you — `/tests export-ignore` meant every scaffolded project
 * arrived with a `phpunit.xml` pointing at `tests`, an `autoload-dev` mapping `Tests\` to `tests/`, and no
 * `tests/` at all, so the first `vendor/bin/phpunit` fatalled with
 * `Trait "Tests\CreatesApplication" not found` before running a single assertion.
 */
$skeleton = dirname(__DIR__).'/skeleton';

it('does not export-ignore anything a scaffolded project needs', function () use ($skeleton) {
    $gitattributes = $skeleton.'/.gitattributes';

    if (! is_file($gitattributes)) {
        expect(true)->toBeTrue(); // nothing is excluded at all, which is also correct

        return;
    }

    $ignored = [];

    foreach (file($gitattributes, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), '#') || ! str_contains($line, 'export-ignore')) {
            continue;
        }

        $fields = preg_split('/\s+/', trim($line));
        $ignored[] = is_array($fields) && $fields !== [] ? trim((string) $fields[0]) : '';
    }

    expect($ignored)->not->toContain('/tests')
        ->and($ignored)->not->toContain('tests')
        ->and($ignored)->not->toContain('/tests/');
});

it('ships the test scaffold its own phpunit.xml and autoload-dev depend on', function () use ($skeleton) {
    $decoded = json_decode((string) file_get_contents($skeleton.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);

    /** @var array<string, mixed> $composer */
    $composer = is_array($decoded) ? $decoded : [];

    /** @var array<string, mixed> $autoloadDev */
    $autoloadDev = is_array($composer['autoload-dev'] ?? null) ? $composer['autoload-dev'] : [];

    /** @var array<string, string> $psr4 */
    $psr4 = is_array($autoloadDev['psr-4'] ?? null) ? $autoloadDev['psr-4'] : [];

    foreach ($psr4 as $prefix => $dir) {
        expect(is_dir($skeleton.'/'.rtrim($dir, '/')))
            ->toBeTrue("autoload-dev maps {$prefix} to {$dir}, which the skeleton does not ship");
    }

    // The two files Laravel's own base test case needs; without either, every test in a fresh project fatals.
    expect(is_file($skeleton.'/tests/TestCase.php'))->toBeTrue()
        ->and(is_file($skeleton.'/tests/CreatesApplication.php'))->toBeTrue();
});
