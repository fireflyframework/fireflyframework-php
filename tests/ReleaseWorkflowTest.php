<?php

// tests/ReleaseWorkflowTest.php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

/**
 * Narrow a Yaml::parseFile() `mixed` result (or a nested offset of one) to a YAML mapping.
 *
 * @return array<string, mixed>
 */
function asYamlMap(mixed $value, string $what): array
{
    if (! is_array($value)) {
        throw new RuntimeException("{$what} is not a YAML mapping.");
    }

    /** @var array<string, mixed> $value */
    return $value;
}

/**
 * Narrow a Yaml::parseFile() `mixed` result (or a nested offset of one) to a YAML sequence.
 *
 * @return list<mixed>
 */
function asYamlList(mixed $value, string $what): array
{
    if (! is_array($value) || ! array_is_list($value)) {
        throw new RuntimeException("{$what} is not a YAML sequence.");
    }

    return $value;
}

/**
 * @return array<string, mixed>
 */
function releaseWorkflowYaml(): array
{
    $wf = dirname(__DIR__).'/.github/workflows/release.yml';

    return asYamlMap(Yaml::parseFile($wf), 'release.yml root');
}

it('release.yml exists', function () {
    $wf = dirname(__DIR__).'/.github/workflows/release.yml';
    expect(is_file($wf))->toBeTrue('release.yml missing');
});

it('triggers only on a pushed v* tag', function () {
    $yaml = releaseWorkflowYaml();

    $on = asYamlMap($yaml['on'] ?? null, 'on');
    expect($on)->toHaveKey('push')
        ->and($on)->not->toHaveKey('pull_request');

    $push = asYamlMap($on['push'] ?? null, 'on.push');
    expect($push)->not->toHaveKey('branches');

    $tags = asYamlList($push['tags'] ?? null, 'on.push.tags');
    expect($tags)->toContain('v*');
});

it('validates the tagged single package without cross-repository credentials', function () {
    $yaml = releaseWorkflowYaml();
    $jobs = asYamlMap($yaml['jobs'] ?? null, 'jobs');
    expect(array_keys($jobs))->toBe(['package', 'publish']);
    $package = asYamlMap($jobs['package'], 'package');
    $steps = asYamlList($package['steps'] ?? null, 'steps');
    $commands = [];
    foreach ($steps as $step) {
        $map = asYamlMap($step, 'step');
        if (is_string($map['run'] ?? null)) {
            $commands[] = $map['run'];
            expect($map['run'])->not->toContain('github.event.');
        }
    }
    expect($commands)->toContain('composer check', 'composer mono-validate', 'composer test:package');
    $permissions = asYamlMap($yaml['permissions'] ?? null, 'permissions');
    expect($permissions)->toBe(['contents' => 'read']);
    $blob = (string) file_get_contents(dirname(__DIR__).'/.github/workflows/release.yml');
    expect($blob)->not->toContain('ACCESS_TOKEN', 'monorepo-split', 'git push');
});

it('publishes a GitHub release only after the tag installs from Packagist', function () {
    $jobs = asYamlMap(releaseWorkflowYaml()['jobs'] ?? null, 'jobs');
    $publish = asYamlMap($jobs['publish'] ?? null, 'publish');
    expect($publish['needs'] ?? null)->toBe('package');
    $steps = asYamlList($publish['steps'] ?? null, 'publish.steps');
    $commands = [];
    foreach ($steps as $step) {
        $map = asYamlMap($step, 'step');
        if (is_string($map['run'] ?? null)) {
            $commands[] = $map['run'];
        }
    }
    $commands = implode("\n", $commands);
    expect($commands)->toContain('scripts/check-package-install.php --published', 'gh release create');
    $verifyPosition = strpos($commands, '--published');
    $releasePosition = strpos($commands, 'gh release create');
    if ($verifyPosition === false || $releasePosition === false) {
        throw new RuntimeException('The publication steps are missing.');
    }
    expect($verifyPosition)->toBeLessThan($releasePosition);
});

it('waits for the tag on Composer\'s metadata endpoint, not on the CDN-cached web API', function () {
    $blob = (string) file_get_contents(dirname(__DIR__).'/.github/workflows/release.yml');
    expect($blob)->toContain('https://repo.packagist.org/p2/fireflyframework/larafly.json')
        ->not->toContain('https://packagist.org/packages/fireflyframework/larafly.json');
});
