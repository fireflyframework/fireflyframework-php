<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\FeatureFlags\Evaluation\FlagdEvaluator;
use Firefly\FeatureFlags\FeatureFlagsServiceProvider;
use Firefly\FeatureFlags\FeatureFlagsWiringProvider;
use Firefly\FeatureFlags\Telemetry\FeatureFlagMetrics;
use Firefly\FeatureFlags\Tests\Support\StaticFlagdEvaluator;
use Firefly\Observability\FeatureFlags\MeterRegistryFeatureFlagMetrics;
use Firefly\Observability\Metrics\MetricsRecorder;
use Firefly\Observability\Metrics\SimpleMeterRegistry;
use Firefly\Observability\ObservabilityServiceProvider;

it('counts evaluations as feature_flag_evaluations_total{flag, variant, reason}', function (): void {
    $recorder = new class implements MetricsRecorder
    {
        /** @var list<array{string, array<string, string>, float}> */
        public array $increments = [];

        public function increment(string $name, array $tags = [], float $amount = 1.0): void
        {
            $this->increments[] = [$name, $tags, $amount];
        }

        public function record(string $name, array $tags = [], float $seconds = 0.0): void {}

        public function setGauge(string $name, array $tags, float $value): void {}
    };

    (new MeterRegistryFeatureFlagMetrics($recorder))->recordEvaluation('new-checkout', 'on', 'TARGETING_MATCH');

    expect($recorder->increments)->toBe([['feature_flag_evaluations_total', ['flag' => 'new-checkout', 'variant' => 'on', 'reason' => 'TARGETING_MATCH'], 1.0]]);
});

it('accumulates one counter per flag, variant and reason on the MeterRegistry', function (): void {
    $registry = new SimpleMeterRegistry;
    $metrics = new MeterRegistryFeatureFlagMetrics($registry);

    $metrics->recordEvaluation('new-checkout', 'on', 'STATIC');
    $metrics->recordEvaluation('new-checkout', 'on', 'STATIC');
    $metrics->recordEvaluation('new-checkout', 'none', 'ERROR');

    expect($registry->counter('feature_flag_evaluations_total', ['flag' => 'new-checkout', 'variant' => 'on', 'reason' => 'STATIC'])->count())->toBe(2.0)
        ->and($registry->counter('feature_flag_evaluations_total', ['flag' => 'new-checkout', 'variant' => 'none', 'reason' => 'ERROR'])->count())->toBe(1.0);
});

it('replaces the feature-flags NoOp when observability is installed', function (): void {
    $context = bootFireflyApp(
        ['firefly' => ['feature-flags' => ['enabled' => true]]],
        [ObservabilityServiceProvider::class, FeatureFlagsServiceProvider::class, FeatureFlagsWiringProvider::class],
        bindings: [FlagdEvaluator::class => new StaticFlagdEvaluator],
        needs: ['cache'],
    );

    expect($context->get(FeatureFlagMetrics::class))->toBeInstanceOf(MeterRegistryFeatureFlagMetrics::class);
});

it('backs the port with no meter while feature flags are off or metrics are disabled', function (): void {
    $meter = static fn (ApplicationContext $context): ?object => $context->has(FeatureFlagMetrics::class) ? $context->get(FeatureFlagMetrics::class) : null;
    $flagsOff = bootFireflyApp(
        [],
        [ObservabilityServiceProvider::class, FeatureFlagsServiceProvider::class, FeatureFlagsWiringProvider::class],
        needs: ['cache'],
    );
    $metricsOff = bootFireflyApp(
        ['firefly' => ['feature-flags' => ['enabled' => true], 'observability' => ['metrics' => ['enabled' => false]]]],
        [ObservabilityServiceProvider::class, FeatureFlagsServiceProvider::class, FeatureFlagsWiringProvider::class],
        needs: ['cache'],
    );

    // With flags off nothing of firefly/feature-flags is registered; with metrics off the package's NoOp (T11) may
    // stand in — never the MeterRegistry-backed meter.
    expect($meter($flagsOff))->toBeNull()
        ->and($meter($metricsOff))->not->toBeInstanceOf(MeterRegistryFeatureFlagMetrics::class);
});
