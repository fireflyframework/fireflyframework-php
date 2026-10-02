<?php

declare(strict_types=1);

use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\FeatureFlags\Context\EvaluationContextResolver;
use Firefly\FeatureFlags\Event\FeatureFlagEvaluated;
use Firefly\FeatureFlags\FeatureFlags;
use Firefly\FeatureFlags\FlagEvaluation;
use Firefly\FeatureFlags\Provider\FireflyFlagProvider;
use Firefly\FeatureFlags\Telemetry\ExposureEventHook;
use Firefly\FeatureFlags\Telemetry\FeatureFlagMetrics;
use Firefly\FeatureFlags\Telemetry\MetricsHook;
use Firefly\FeatureFlags\Tests\Support\RecordingLogger;
use Firefly\FeatureFlags\Tests\Support\StaticDocument;
use Firefly\FeatureFlags\Tests\Support\StaticFlagdEvaluator;
use Firefly\Testing\Double\RecordingApplicationEventPublisher;
use OpenFeature\implementation\flags\EvaluationContext as FlagsEvaluationContext;
use OpenFeature\implementation\hooks\HookContextBuilder;
use OpenFeature\implementation\hooks\HookHints as FlagsHookHints;
use OpenFeature\implementation\provider\AbstractProvider;
use OpenFeature\implementation\provider\ResolutionDetailsBuilder;
use OpenFeature\implementation\provider\ResolutionError as ProviderResolutionError;
use OpenFeature\interfaces\flags\EvaluationContext;
use OpenFeature\interfaces\flags\FlagValueType;
use OpenFeature\interfaces\hooks\Hook;
use OpenFeature\interfaces\hooks\HookContext;
use OpenFeature\interfaces\hooks\HookHints;
use OpenFeature\interfaces\provider\ErrorCode;
use OpenFeature\interfaces\provider\ResolutionDetails;
use OpenFeature\interfaces\provider\ResolutionError;
use OpenFeature\interfaces\provider\ThrowableWithResolutionError;
use OpenFeature\isolated\OpenFeatureAPIFactory;

final class FeatureFlagsRecordingMetrics implements FeatureFlagMetrics
{
    /** @var list<string> */
    public array $rows = [];

    public bool $broken = false;

    public function recordEvaluation(string $flag, string $variant, string $reason): void
    {
        if ($this->broken) {
            throw new RuntimeException('meter registry down');
        }
        $this->rows[] = "{$flag}|{$variant}|{$reason}";
    }
}

/** An application hook that throws in one phase (`before` or `after`), which sends the SDK down its error path. */
final class FeatureFlagsFailingHook implements Hook
{
    public function __construct(private readonly string $phase) {}

    public function before(HookContext $context, HookHints $hints): ?EvaluationContext
    {
        if ($this->phase === 'before') {
            throw new RuntimeException('before hook failed');
        }

        return null;
    }

    public function after(HookContext $context, ResolutionDetails $details, HookHints $hints): void
    {
        if ($this->phase === 'after') {
            throw new RuntimeException('after hook failed');
        }
    }

    public function error(HookContext $context, Throwable $error, HookHints $hints): void {}

    public function finally(HookContext $context, HookHints $hints): void {}

    public function supportsFlagValueType(string $flagValueType): bool
    {
        return true;
    }
}

/** An application's own provider: answers the same details for every flag, or throws. */
final class FeatureFlagsScriptedProvider extends AbstractProvider
{
    public function __construct(
        private readonly ?ResolutionDetails $details = null,
        private readonly ?Throwable $failure = null,
    ) {}

    public function resolveBooleanValue(string $flagKey, bool $defaultValue, ?EvaluationContext $context = null): ResolutionDetails
    {
        return $this->answer();
    }

    public function resolveStringValue(string $flagKey, string $defaultValue, ?EvaluationContext $context = null): ResolutionDetails
    {
        return $this->answer();
    }

    public function resolveIntegerValue(string $flagKey, int $defaultValue, ?EvaluationContext $context = null): ResolutionDetails
    {
        return $this->answer();
    }

    public function resolveFloatValue(string $flagKey, float $defaultValue, ?EvaluationContext $context = null): ResolutionDetails
    {
        return $this->answer();
    }

    /**
     * @param  mixed[]  $defaultValue
     */
    public function resolveObjectValue(string $flagKey, array $defaultValue, ?EvaluationContext $context = null): ResolutionDetails
    {
        return $this->answer();
    }

    private function answer(): ResolutionDetails
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->details ?? (new ResolutionDetailsBuilder)->build();
    }
}

/** A provider failure that carries its OpenFeature error code, as the SDK's client reads it. */
final class FeatureFlagsProviderNotReady extends RuntimeException implements ThrowableWithResolutionError
{
    public function getResolutionError(): ResolutionError
    {
        return new ProviderResolutionError(ErrorCode::PROVIDER_NOT_READY(), 'warming up');
    }
}

/** @param list<Hook> $hooks */
function featureFlagsWithHooks(array $hooks): FeatureFlags
{
    $document = StaticDocument::json('{"flags":{"on":{"state":"ENABLED","variants":{"on":true,"off":false},"defaultVariant":"on"},"paused":{"state":"DISABLED","variants":{"on":true},"defaultVariant":"on"},"text":{"state":"ENABLED","variants":{"a":"A"},"defaultVariant":"a"}}}');

    return new FeatureFlags(new FireflyFlagProvider($document, new StaticFlagdEvaluator), new EvaluationContextResolver, $hooks, $document, OpenFeatureAPIFactory::createAPI());
}

/** @param list<Hook> $hooks */
function featureFlagsWithProvider(AbstractProvider $provider, array $hooks): FeatureFlags
{
    return new FeatureFlags($provider, new EvaluationContextResolver, $hooks, null, OpenFeatureAPIFactory::createAPI());
}

it('counts every evaluation with variant none and reason ERROR for failures', function (): void {
    $metrics = new FeatureFlagsRecordingMetrics;
    $flags = featureFlagsWithHooks([new MetricsHook($metrics)]);

    $flags->isEnabled('on');
    $flags->isEnabled('paused');
    $flags->isEnabled('missing');
    $flags->isEnabled('text');

    expect($metrics->rows)->toBe(['on|on|STATIC', 'paused|none|DISABLED', 'missing|none|ERROR', 'text|none|ERROR']);
});

it('never changes the value when the metrics recorder throws', function (): void {
    $metrics = new FeatureFlagsRecordingMetrics;
    $metrics->broken = true;
    $logger = new RecordingLogger;

    $value = featureFlagsWithHooks([new MetricsHook($metrics, $logger)])->isEnabled('on');

    expect($value)->toBeTrue()
        ->and($logger->count('debug', 'meter registry down'))->toBe(1);
});

it('publishes one exposure event per evaluation with the targeting key', function (): void {
    $events = new RecordingApplicationEventPublisher;

    featureFlagsWithHooks([new ExposureEventHook($events)])->isEnabled('on', targetingKey: 'u-42');

    /** @var FeatureFlagEvaluated $event */
    $event = $events->ofType(FeatureFlagEvaluated::class)[0];

    expect($events->events)->toHaveCount(1)
        ->and([$event->key, $event->value, $event->variant, $event->reason, $event->errorCode, $event->targetingKey])
        ->toBe(['on', true, 'on', 'STATIC', null, 'u-42']);
});

it('never changes the value when an exposure listener throws', function (): void {
    $throwing = new class implements ApplicationEventPublisher
    {
        public function publish(object $event): void
        {
            throw new RuntimeException('analytics pipeline down');
        }
    };
    $logger = new RecordingLogger;

    expect(featureFlagsWithHooks([new ExposureEventHook($throwing, $logger)])->isEnabled('on'))->toBeTrue()
        ->and($logger->count('debug', 'analytics pipeline down'))->toBe(1);
});

it('never lets a failure out of any hook phase, whatever the SDK does around it', function (): void {
    $metrics = new FeatureFlagsRecordingMetrics;
    $metrics->broken = true;
    $throwing = new class implements ApplicationEventPublisher
    {
        public function publish(object $event): void
        {
            throw new RuntimeException('analytics pipeline down');
        }
    };
    $logger = new RecordingLogger;
    $hooks = [new MetricsHook($metrics, $logger), new ExposureEventHook($throwing, $logger)];
    $context = (new HookContextBuilder)->withFlagKey('on')->withType(FlagValueType::BOOLEAN)->withDefaultValue(false)
        ->withEvaluationContext(new FlagsEvaluationContext('u-42'))->build();
    $failed = (new HookContextBuilder)->withFlagKey('on')->withType(FlagValueType::BOOLEAN)->withDefaultValue(false)
        ->withEvaluationContext(new FlagsEvaluationContext('u-42'))->build();
    $details = (new ResolutionDetailsBuilder)->withValue(true)->withVariant('on')->withReason('STATIC')->build();
    $hints = new FlagsHookHints;

    foreach ($hooks as $hook) {
        expect($hook->before($context, $hints))->toBeNull();
        $hook->after($context, $details, $hints);
        $hook->finally($context, $hints);
        $hook->error($failed, new RuntimeException('provider down'), $hints);
        $hook->finally($failed, $hints);
    }

    expect($logger->count('debug', 'meter registry down'))->toBe(2)
        ->and($logger->count('debug', 'analytics pipeline down'))->toBe(2);
});

it('records neither a metric nor an exposure event for a preview, whatever it answers', function (): void {
    $metrics = new FeatureFlagsRecordingMetrics;
    $events = new RecordingApplicationEventPublisher;
    $hooks = [new MetricsHook($metrics), new ExposureEventHook($events)];
    $flags = featureFlagsWithHooks($hooks);
    $failing = featureFlagsWithHooks([new FeatureFlagsFailingHook('before'), ...$hooks]);
    $outcome = static fn (FlagEvaluation $evaluation): string => $evaluation->reason.'|'.($evaluation->errorCode ?? '-');

    $previews = [
        $flags->details('on', false, ambient: false),
        $flags->details('missing', false, ambient: false),
        $flags->details('text', false, ambient: false),
        $failing->details('on', false, ambient: false),
    ];

    expect(array_map($outcome, $previews))->toBe(['STATIC|-', 'ERROR|FLAG_NOT_FOUND', 'ERROR|TYPE_MISMATCH', 'ERROR|GENERAL'])
        ->and($metrics->rows)->toBe([])
        ->and($events->events)->toBe([]);

    // The same evaluations outside a preview are counted and exposed: the hooks are attached and listening.
    $evaluations = [
        $flags->details('on', false),
        $flags->details('missing', false),
        $flags->details('text', false),
        $failing->details('on', false),
    ];

    expect(array_map($outcome, $evaluations))->toBe(['STATIC|-', 'ERROR|FLAG_NOT_FOUND', 'ERROR|TYPE_MISMATCH', 'ERROR|GENERAL'])
        ->and($metrics->rows)->toBe(['on|on|STATIC', 'missing|none|ERROR', 'text|none|ERROR', 'on|none|ERROR'])
        ->and($events->ofType(FeatureFlagEvaluated::class))->toHaveCount(4);
});

it('counts and exposes an evaluation once, as the ERROR the caller got, when a hook after them fails', function (): void {
    $metrics = new FeatureFlagsRecordingMetrics;
    $events = new RecordingApplicationEventPublisher;
    // Client hooks run `after` in reverse order: the failing hook's `after` runs once both of ours have seen a
    // successful resolution, and the SDK then answers ERROR + the default and runs every `error` hook.
    $flags = featureFlagsWithHooks([new FeatureFlagsFailingHook('after'), new MetricsHook($metrics), new ExposureEventHook($events)]);

    $details = $flags->details('on', false, targetingKey: 'u-42');

    /** @var list<FeatureFlagEvaluated> $exposures */
    $exposures = $events->ofType(FeatureFlagEvaluated::class);

    expect([$details->value, $details->reason, $details->errorCode])->toBe([false, 'ERROR', 'GENERAL'])
        ->and($metrics->rows)->toBe(['on|none|ERROR'])
        ->and($exposures)->toHaveCount(1)
        ->and([$exposures[0]->key, $exposures[0]->value, $exposures[0]->variant, $exposures[0]->reason, $exposures[0]->errorCode, $exposures[0]->targetingKey])
        ->toBe(['on', false, null, 'ERROR', 'GENERAL', 'u-42']);
});

it('exposes a failure with the error code the caller got and the default it was served', function (): void {
    $metrics = new FeatureFlagsRecordingMetrics;
    $events = new RecordingApplicationEventPublisher;
    $hooks = [new MetricsHook($metrics), new ExposureEventHook($events)];

    featureFlagsWithHooks($hooks)->isEnabled('missing', true);
    featureFlagsWithHooks($hooks)->isEnabled('paused');
    $notReady = featureFlagsWithProvider(new FeatureFlagsScriptedProvider(failure: new FeatureFlagsProviderNotReady('warming up')), $hooks)
        ->details('banner', 'plain');

    /** @var list<FeatureFlagEvaluated> $exposures */
    $exposures = $events->ofType(FeatureFlagEvaluated::class);
    $rows = array_map(static fn (FeatureFlagEvaluated $event): array => [$event->key, $event->value, $event->variant, $event->reason, $event->errorCode], $exposures);

    expect($notReady->errorCode)->toBe('PROVIDER_NOT_READY')
        ->and($rows)->toBe([
            ['missing', true, null, 'ERROR', 'FLAG_NOT_FOUND'],
            ['paused', false, null, 'DISABLED', null],
            ['banner', 'plain', null, 'ERROR', 'PROVIDER_NOT_READY'],
        ])
        ->and($metrics->rows)->toBe(['missing|none|ERROR', 'paused|none|DISABLED', 'banner|none|ERROR']);
});

it('exposes a copy of the served value, which the caller cannot change afterwards', function (): void {
    $events = new RecordingApplicationEventPublisher;
    $hooks = [new ExposureEventHook($events)];
    $style = (object) ['color' => 'red'];
    $served = featureFlagsWithProvider(new FeatureFlagsScriptedProvider(
        (new ResolutionDetailsBuilder)->withValue(['style' => $style])->withVariant('red')->withReason('STATIC')->build(),
    ), $hooks);
    $fallback = ['style' => (object) ['color' => 'blue']];

    $value = $served->getObject('theme');
    featureFlagsWithProvider(new FeatureFlagsScriptedProvider(failure: new RuntimeException('provider down')), $hooks)->getObject('theme', $fallback);

    // The caller holds the very instance the provider served (PHP shares objects inside arrays) and changes it.
    expect($value['style'] ?? null)->toBe($style);
    $style->color = 'green';
    $fallback['style']->color = 'black';

    /** @var list<FeatureFlagEvaluated> $exposures */
    $exposures = $events->ofType(FeatureFlagEvaluated::class);

    expect($exposures)->toHaveCount(2)
        ->and($exposures[0]->value)->toEqual(['style' => (object) ['color' => 'red']])
        ->and($exposures[1]->value)->toEqual(['style' => (object) ['color' => 'blue']])
        ->and([$exposures[1]->reason, $exposures[1]->errorCode])->toBe(['ERROR', 'GENERAL']);
});
