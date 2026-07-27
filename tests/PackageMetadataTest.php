<?php

declare(strict_types=1);

/** @return list<string> absolute composer.json paths of every publishable unit */
function fireflyComposerFiles(): array
{
    $root = dirname(__DIR__);
    $files = glob($root.'/packages/*/composer.json') ?: [];
    $files[] = $root.'/skeleton/composer.json';

    return $files;
}

it('carries full Packagist metadata + branch-alias and NO version field on every unit', function () {
    foreach (fireflyComposerFiles() as $file) {
        /** @var array<string, mixed> $json */
        $json = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        $where = str_replace(dirname(__DIR__).'/', '', $file);

        expect($json)->not->toHaveKey('version', "version field present in {$where}")
            ->and($json)->toHaveKeys(['authors', 'keywords', 'homepage', 'support'], "missing metadata in {$where}");

        expect($json['support'])->toHaveKeys(['issues', 'source'], "incomplete support in {$where}");
        expect($json['homepage'])->toBe('https://github.com/fireflyframework/fireflyframework-php', "homepage in {$where}");

        /** @var array<int, array<string, mixed>> $authors */
        $authors = is_array($json['authors'] ?? null) ? $json['authors'] : [];
        expect($authors[0]['name'] ?? null)->toBe('Firefly Software Solutions Inc.', "author in {$where}");

        /** @var array<string, mixed> $extra */
        $extra = is_array($json['extra'] ?? null) ? $json['extra'] : [];
        /** @var array<string, mixed> $branchAlias */
        $branchAlias = is_array($extra['branch-alias'] ?? null) ? $extra['branch-alias'] : [];
        expect($branchAlias['dev-main'] ?? null)->toBe('26.x-dev', "branch-alias in {$where}");
    }
});
