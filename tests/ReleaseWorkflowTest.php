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

it('the split matrix covers exactly the 26 publishable units, each mapped to fireflyframework/firefly-<dir>', function () {
    $root = dirname(__DIR__);
    $yaml = releaseWorkflowYaml();

    $jobs = asYamlMap($yaml['jobs'] ?? null, 'jobs');
    $splitJob = asYamlMap($jobs['split'] ?? null, 'jobs.split');
    $strategy = asYamlMap($splitJob['strategy'] ?? null, 'jobs.split.strategy');
    $matrixMap = asYamlMap($strategy['matrix'] ?? null, 'jobs.split.strategy.matrix');
    $matrix = asYamlList($matrixMap['package'] ?? null, 'jobs.split.strategy.matrix.package');

    // Build the expected unit list independently from the filesystem — this is the
    // ground truth the matrix must match exactly (fails if a unit is added/removed
    // under packages/* without updating the workflow, and fails if the workflow lists
    // a unit that doesn't exist).
    /** @var list<string> $expectedLocals */
    $expectedLocals = array_map(
        static fn (string $dir): string => 'packages/'.basename($dir),
        glob($root.'/packages/*', GLOB_ONLYDIR) ?: []
    );
    $expectedLocals[] = 'skeleton';
    sort($expectedLocals);

    expect($expectedLocals)->toHaveCount(26);

    /** @var list<string> $actualLocals */
    $actualLocals = [];
    /** @var array<string, string> $actualSplitByLocal */
    $actualSplitByLocal = [];

    foreach ($matrix as $row) {
        $rowMap = asYamlMap($row, 'matrix row');
        $local = $rowMap['local'] ?? null;
        $splitName = $rowMap['split'] ?? null;

        if (! is_string($local) || ! is_string($splitName)) {
            throw new RuntimeException('matrix row local/split must be strings.');
        }

        $actualLocals[] = $local;
        $actualSplitByLocal[$local] = $splitName;
    }
    sort($actualLocals);

    expect($actualLocals)->toBe($expectedLocals, 'split matrix does not match packages/* + skeleton exactly');

    // Every mirror target must be fireflyframework/firefly-<dir> using the LOCAL dirname
    // (e.g. packages/eda-postgres -> firefly-eda-postgres), consumed by
    // split-repository-organization: fireflyframework alongside split-repository-name.
    foreach ($actualSplitByLocal as $local => $splitName) {
        $dir = basename($local);
        expect($splitName)->toBe("firefly-{$dir}", "mirror name for {$local} must be firefly-{$dir}");
    }
});

it('uses symplify/monorepo-split-github-action (v11 monorepo-builder has no split command)', function () {
    $blob = (string) file_get_contents(dirname(__DIR__).'/.github/workflows/release.yml');

    expect($blob)->toContain('symplify/monorepo-split-github-action')
        ->and($blob)->not->toContain('monorepo-builder split');
});

it('references the cross-repo PAT only via secrets.ACCESS_TOKEN, never hardcoded', function () {
    $blob = (string) file_get_contents(dirname(__DIR__).'/.github/workflows/release.yml');

    expect($blob)->toContain('${{ secrets.ACCESS_TOKEN }}');

    // No plausible hardcoded GitHub PAT literal (ghp_/github_pat_ prefixes).
    expect($blob)->not->toMatch('/ghp_[A-Za-z0-9]{20,}/')
        ->and($blob)->not->toMatch('/github_pat_[A-Za-z0-9_]{20,}/');
});

it('checks out full history (fetch-depth: 0) for the split', function () {
    $yaml = releaseWorkflowYaml();
    $jobs = asYamlMap($yaml['jobs'] ?? null, 'jobs');
    $splitJob = asYamlMap($jobs['split'] ?? null, 'jobs.split');
    $steps = asYamlList($splitJob['steps'] ?? null, 'jobs.split.steps');

    /** @var array<string, mixed> $checkout */
    $checkout = [];
    foreach ($steps as $step) {
        $stepMap = asYamlMap($step, 'step');
        $uses = $stepMap['uses'] ?? null;
        if (is_string($uses) && str_starts_with($uses, 'actions/checkout@')) {
            $checkout = $stepMap;
            break;
        }
    }

    expect($checkout)->not->toBe([], 'no actions/checkout step found');

    $with = asYamlMap($checkout['with'] ?? null, 'checkout.with');
    expect($with['fetch-depth'] ?? null)->toBe(0);
});

it('does not interpolate untrusted github.event.* into a run: step', function () {
    $yaml = releaseWorkflowYaml();
    $jobs = asYamlMap($yaml['jobs'] ?? null, 'jobs');
    $splitJob = asYamlMap($jobs['split'] ?? null, 'jobs.split');
    $steps = asYamlList($splitJob['steps'] ?? null, 'jobs.split.steps');

    foreach ($steps as $step) {
        $stepMap = asYamlMap($step, 'step');
        $run = $stepMap['run'] ?? null;
        if (is_string($run)) {
            expect($run)->not->toContain('github.event.');
        }
    }
});
