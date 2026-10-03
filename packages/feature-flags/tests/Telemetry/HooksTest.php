<?php

declare(strict_types=1);

use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\FeatureFlags\Context\EvaluationContextResolver;
use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Evaluation\FlagType;
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
use Psr\Log\AbstractLogger;
use Symfony\Component\Process\Process;

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

it('copies shared and cyclic object graphs within bounded memory on both SDK paths', function (bool $failed): void {
    $script = <<<'SCRIPT'
require 'vendor/autoload.php';
$leaf = (object) ['label' => 'original'];
$leaf->self = $leaf;
$graph = $leaf;
for ($i = 0; $i < 20; $i++) {
    $graph = (object) ['left' => $graph, 'right' => $graph];
}
$events = new Firefly\Testing\Double\RecordingApplicationEventPublisher;
$hook = new Firefly\FeatureFlags\Telemetry\ExposureEventHook($events);
$provider = new OpenFeature\implementation\provider\NoOpProvider;
$flags = new Firefly\FeatureFlags\FeatureFlags($provider, new Firefly\FeatureFlags\Context\EvaluationContextResolver, [$hook], null, OpenFeature\isolated\OpenFeatureAPIFactory::createAPI());
SCRIPT;
    if ($failed) {
        $script .= <<<'SCRIPT'
$provider = new class extends OpenFeature\implementation\provider\NoOpProvider {
    public function resolveObjectValue(string $flagKey, array $defaultValue, ?OpenFeature\interfaces\flags\EvaluationContext $context = null): OpenFeature\interfaces\provider\ResolutionDetails {
        throw new RuntimeException('provider down');
    }
};
$flags = new Firefly\FeatureFlags\FeatureFlags($provider, new Firefly\FeatureFlags\Context\EvaluationContextResolver, [$hook], null, OpenFeature\isolated\OpenFeatureAPIFactory::createAPI());
SCRIPT;
    }
    $script .= <<<'SCRIPT'
foreach ([1, 2] as $evaluation) {
    $served = $flags->getObject('graph', ['tree' => $graph]);
    if ($served['tree'] !== $graph) { throw new RuntimeException('changed caller value'); }
}
$first = $events->events[0]->value['tree'];
$second = $events->events[1]->value['tree'];
if ($first === $graph || $first === $second) { throw new RuntimeException('shared snapshot'); }
for ($i = 0; $i < 20; $i++) {
    if ($first->left !== $first->right) { throw new RuntimeException('lost alias'); }
    $first = $first->left;
}
$leaf->label = 'changed';
if ($first->self !== $first || $first === $leaf || $first->label !== 'original') { throw new RuntimeException('lost cycle or isolation'); }
echo $events->events[0]->reason;
SCRIPT;
    $process = new Process([PHP_BINARY, '-d', 'memory_limit=32M', '-r', $script], dirname(__DIR__, 4));
    $process->setTimeout(10);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toBe($failed ? 'ERROR' : 'UNKNOWN');
})->with([false, true]);

it('contains copy failures and drops stale successful exposures on an unsupported default', function (): void {
    $events = new RecordingApplicationEventPublisher;
    $logger = new RecordingLogger;
    $array = [];
    $array['self'] = &$array;
    $flags = featureFlagsWithProvider(new FeatureFlagsScriptedProvider(
        (new ResolutionDetailsBuilder)->withValue(['ok' => true])->withReason('STATIC')->build(),
    ), [new FeatureFlagsFailingHook('after'), new ExposureEventHook($events, $logger)]);

    $value = $flags->getObject('graph', $array);

    expect(array_keys($value))->toBe(['self'])
        ->and($events->events)->toBe([])
        ->and($logger->count('debug', 'array references'))->toBe(1);
});

it('keeps successful evaluation unchanged when its array reference snapshot is unsupported', function (): void {
    $events = new RecordingApplicationEventPublisher;
    $logger = new RecordingLogger;
    $shared = ['ok' => true];
    $value = ['left' => &$shared, 'right' => &$shared];
    $flags = featureFlagsWithProvider(new FeatureFlagsScriptedProvider(
        (new ResolutionDetailsBuilder)->withValue($value)->withReason('STATIC')->build(),
    ), [new ExposureEventHook($events, $logger)]);

    expect($flags->getObject('graph'))->toBe($value)
        ->and($events->events)->toBe([])
        ->and($logger->count('debug', 'array references'))->toBe(1);
});

it('contains a broken debug logger in both hooks without SDK protection', function (): void {
    $metrics = new FeatureFlagsRecordingMetrics;
    $metrics->broken = true;
    $publisher = new class implements ApplicationEventPublisher
    {
        public function publish(object $event): void
        {
            throw new RuntimeException('publisher down');
        }
    };
    $logger = new class extends AbstractLogger
    {
        public int $calls = 0;

        public function log($level, string|Stringable $message, array $context = []): void
        {
            $this->calls++;
            throw new RuntimeException('logger down');
        }
    };
    $context = (new HookContextBuilder)->withFlagKey('on')->withType(FlagValueType::BOOLEAN)->withDefaultValue(false)->withEvaluationContext(new FlagsEvaluationContext)->build();
    $details = (new ResolutionDetailsBuilder)->withValue(true)->build();
    $hints = new FlagsHookHints;
    foreach ([new MetricsHook($metrics, $logger), new ExposureEventHook($publisher, $logger)] as $hook) {
        expect($hook->before($context, $hints))->toBeNull();
        $hook->after($context, $details, $hints);
        $hook->finally($context, $hints);
        $hook->error($context, new RuntimeException('provider down'), $hints);
        $hook->finally($context, $hints);
    }
    expect($logger->calls)->toBe(4);
});

it('limits snapshot isolation to JSON values and keeps nested foreign objects by identity', function (): void {
    $events = new RecordingApplicationEventPublisher;
    $date = new DateTime('2026-01-01');
    $flags = featureFlagsWithProvider(new FeatureFlagsScriptedProvider(
        (new ResolutionDetailsBuilder)->withValue(['nested' => (object) ['date' => $date]])->build(),
    ), [new ExposureEventHook($events)]);
    $flags->getObject('date');
    /** @var FeatureFlagEvaluated $event */
    $event = $events->events[0];
    /** @var array{nested: stdClass} $snapshot */
    $snapshot = $event->value;
    expect($snapshot['nested']->date)->toBe($date);
});

it('copies wide valid stored values and ordinary defaults across every copy entry point', function (): void {
    $wide = array_fill(0, 10001, (object) ['label' => 'original']);
    $document = StaticDocument::json(Json::encode([
        'flags' => ['wide' => ['state' => 'ENABLED', 'variants' => ['v' => $wide], 'defaultVariant' => 'v']],
    ]));
    $provider = new FireflyFlagProvider($document, new StaticFlagdEvaluator);
    $events = new RecordingApplicationEventPublisher;
    $flags = new FeatureFlags($provider, new EvaluationContextResolver, [new ExposureEventHook($events)], $document, OpenFeatureAPIFactory::createAPI());
    $resolution = $provider->resolution('wide', FlagType::Object, []);
    $details = $flags->details('wide', []);
    $ownDetails = FlagEvaluation::fromResolution('wide', $resolution);
    $default = ['nested' => (object) ['label' => 'default']];
    $fallback = $flags->details('missing', $default);
    $ownFallback = $provider->resolution('missing', FlagType::Object, $default);
    /** @var FeatureFlagEvaluated $event */
    $event = $events->events[0];
    expect($resolution->value)->toHaveCount(10001)
        ->and($details->value)->toHaveCount(10001)
        ->and($ownDetails->value)->toHaveCount(10001)
        ->and($event->value)->toHaveCount(10001)
        ->and($fallback->value)->toBe(['nested' => ['label' => 'default']])
        ->and($ownFallback->value)->toEqual($default);
});

it('omits excessively deep snapshots without replacing successful SDK values', function (): void {
    $value = ['end' => (object) ['label' => 'original']];
    for ($i = 0; $i < 513; $i++) {
        $value = [$value];
    }
    $events = new RecordingApplicationEventPublisher;
    $logger = new RecordingLogger;
    $flags = featureFlagsWithProvider(new FeatureFlagsScriptedProvider(
        (new ResolutionDetailsBuilder)->withValue($value)->build(),
    ), [new ExposureEventHook($events, $logger)]);
    expect($flags->getObject('deep'))->toBe($value)
        ->and($events->events)->toBe([])
        ->and($logger->count('debug', 'depth limit'))->toBe(1);
});

it('copies repeated by-value arrays without expanding their COW tree', function (bool $failed): void {
    $script = <<<'SCRIPT'
require 'vendor/autoload.php';
$leaf = (object) ['label' => 'original'];
$value = [$leaf];
for ($i = 0; $i < 20; $i++) { $value = [$value, $value]; }
$events = new Firefly\Testing\Double\RecordingApplicationEventPublisher;
$provider = new class extends OpenFeature\implementation\provider\NoOpProvider {
    public bool $failed = false;
    public function resolveObjectValue(string $flagKey, array $defaultValue, ?OpenFeature\interfaces\flags\EvaluationContext $context = null): OpenFeature\interfaces\provider\ResolutionDetails {
        if ($this->failed) { throw new RuntimeException('provider down'); }
        return parent::resolveObjectValue($flagKey, $defaultValue, $context);
    }
};
SCRIPT;
    $script .= '$provider->failed = '.($failed ? 'true' : 'false').';';
    $script .= <<<'SCRIPT'
$flags = new Firefly\FeatureFlags\FeatureFlags($provider, new Firefly\FeatureFlags\Context\EvaluationContextResolver, [new Firefly\FeatureFlags\Telemetry\ExposureEventHook($events)], null, OpenFeature\isolated\OpenFeatureAPIFactory::createAPI());
$served = $flags->getObject('tree', $value);
for ($i = 0; $i < 20; $i++) { $served = $served[0]; }
if ($served[0] !== $leaf) { throw new RuntimeException('changed caller'); }
$copy = $events->events[0]->value;
$left = $copy;
$right = $copy;
for ($i = 0; $i < 20; $i++) { $left = $left[0]; $right = $right[1]; }
$leaf->label = 'changed';
if ($left[0] === $leaf || $left[0] !== $right[0] || $left[0]->label !== 'original') { throw new RuntimeException('lost isolation or alias'); }
$copy[0] = ['replaced'];
if ($events->events[0]->value[0] === ['replaced']) { throw new RuntimeException('lost COW isolation'); }
echo $events->events[0]->reason;
SCRIPT;
    $process = new Process([PHP_BINARY, '-d', 'memory_limit=32M', '-r', $script], dirname(__DIR__, 4));
    $process->setTimeout(10);
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toBe($failed ? 'ERROR' : 'UNKNOWN');
})->with([false, true]);

it('keeps distinct nested arrays distinct without calling foreign serialization methods', function (): void {
    $foreign = new class
    {
        public function __serialize(): array
        {
            throw new RuntimeException('foreign serialization must not run');
        }
    };
    $first = (object) ['label' => 'first'];
    $second = (object) ['label' => 'second'];
    $value = [[['leaf' => $first]], [['leaf' => $second]], ['foreign' => $foreign]];
    $events = new RecordingApplicationEventPublisher;
    $flags = featureFlagsWithProvider(new FeatureFlagsScriptedProvider(
        (new ResolutionDetailsBuilder)->withValue($value)->build(),
    ), [new ExposureEventHook($events)]);
    $flags->getObject('distinct');
    /** @var FeatureFlagEvaluated $event */
    $event = $events->events[0];
    /** @var array{array{array{leaf: stdClass}}, array{array{leaf: stdClass}}, array{foreign: object}} $copy */
    $copy = $event->value;
    expect($copy[0][0]['leaf']->label)->toBe('first')
        ->and($copy[1][0]['leaf']->label)->toBe('second')
        ->and($copy[0][0]['leaf'])->not->toBe($first)
        ->and($copy[1][0]['leaf'])->not->toBe($second)
        ->and($copy[2]['foreign'])->toBe($foreign);
});
