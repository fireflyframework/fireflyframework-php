<?php

declare(strict_types=1);

it('ships the dependencies, autoloads and discovery metadata required by its component code', function () {
    /** @var array<string, mixed> $root */
    $root = json_decode((string) file_get_contents(dirname(__DIR__).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
    expect($root['name'])->toBe('fireflyframework/larafly')->and($root['type'])->toBe('library');

    /** @var array<string, string> $replaced */
    $replaced = $root['replace'];
    /** @var array<string, string> $required */
    $required = $root['require'];
    /** @var array{psr-4: array<string, string>, files: list<string>} $autoload */
    $autoload = $root['autoload'];
    /** @var array{laravel: array{providers: list<string>}} $extra */
    $extra = $root['extra'];

    foreach (glob(dirname(__DIR__).'/packages/*/composer.json') ?: [] as $file) {
        /** @var array{name: string, require?: array<string, string>, autoload?: array{psr-4?: array<string, string>, files?: list<string>}, extra?: array{laravel?: array{providers?: list<string>}}} $module */
        $module = json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
        expect($replaced[$module['name']] ?? null)->toBe('self.version');
        foreach ($module['require'] ?? [] as $name => $constraint) {
            if (str_starts_with($name, 'firefly/') || in_array($name, ['ext-pdo_pgsql', 'orchestra/testbench', 'illuminate/testing'], true)) {
                continue;
            }
            expect($required[$name] ?? null)->toBe($constraint, $module['name'].' needs '.$name);
        }
        $prefix = 'packages/'.basename(dirname($file)).'/';
        foreach ($module['autoload']['psr-4'] ?? [] as $namespace => $path) {
            expect($autoload['psr-4'][$namespace] ?? null)->toBe($prefix.$path);
        }
        foreach ($module['autoload']['files'] ?? [] as $path) {
            expect($autoload['files'])->toContain($prefix.$path);
        }
        foreach ($module['extra']['laravel']['providers'] ?? [] as $provider) {
            expect($extra['laravel']['providers'])->toContain($provider);
        }
    }

    expect($required)->not->toHaveKeys(array_keys($replaced))
        ->not->toHaveKeys(['orchestra/testbench', 'pestphp/pest', 'illuminate/testing']);
});
