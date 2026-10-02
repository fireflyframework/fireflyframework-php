<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Evaluation\DefaultFlagdEvaluator;
use Firefly\FeatureFlags\Tests\Support\ConformanceFiles;
use Firefly\FeatureFlags\Tests\Support\GherkinRunner;
use Firefly\FeatureFlags\Tests\Support\GherkinScenarios;

it('selects the 125 scenarios the reference evaluator passes and skips only @fractional-v1', function (): void {
    $loaded = GherkinScenarios::load(ConformanceFiles::gherkinDirectory());

    expect($loaded['run'])->toHaveCount(125)
        ->and($loaded['skipped'])->toBe(15);
});

it('passes the flagd-testbed evaluator scenario', function (array $steps): void {
    /** @var list<string> $steps */
    $runner = new GherkinRunner(new DefaultFlagdEvaluator, ConformanceFiles::testkitDocument());

    expect($runner->run($steps))->toBe([]);
})->with(fn (): array => GherkinScenarios::dataset(ConformanceFiles::gherkinDirectory()));
