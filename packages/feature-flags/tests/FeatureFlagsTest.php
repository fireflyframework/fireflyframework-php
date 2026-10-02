<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Context\ApplicationEvaluationContextContributor;
use Firefly\FeatureFlags\Context\EvaluationContextBuilder;
use Firefly\FeatureFlags\Context\EvaluationContextContributor;
use Firefly\FeatureFlags\Context\EvaluationContextResolver;
use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Evaluation\FlagType;
use Firefly\FeatureFlags\FeatureFlags;
use Firefly\FeatureFlags\FlagEvaluation;
use Firefly\FeatureFlags\Provider\FireflyFlagProvider;
use Firefly\FeatureFlags\Tests\Support\ProfileVariables;
use Firefly\FeatureFlags\Tests\Support\RecordingHook;
use Firefly\FeatureFlags\Tests\Support\StaticDocument;
use Firefly\FeatureFlags\Tests\Support\StaticFlagdEvaluator;
use Illuminate\Config\Repository;
use OpenFeature\implementation\provider\NoOpProvider;
use OpenFeature\interfaces\flags\API;
use OpenFeature\interfaces\hooks\Hook;
use OpenFeature\isolated\OpenFeatureAPIFactory;

/**
 * @param  list<Hook>  $hooks
 */
function featureFlagsFacade(?API $api = null, ?EvaluationContextResolver $context = null, array $hooks = [], ?StaticFlagdEvaluator $evaluator = null): FeatureFlags
{
    $document = StaticDocument::json('{"flags":{
        "kill-switch":{"state":"ENABLED","variants":{"on":true,"off":false},"defaultVariant":"on","metadata":{"owner":"ops"}},
        "flow":{"state":"ENABLED","variants":{"a":"A","b":"B"},"defaultVariant":"b"},
        "size":{"state":"ENABLED","variants":{"s":10,"l":50},"defaultVariant":"l"},
        "ratio":{"state":"ENABLED","variants":{"low":0.05},"defaultVariant":"low"},
        "banner":{"state":"ENABLED","variants":{"plain":{"title":"Hi"}},"defaultVariant":"plain"},
        "panel":{"state":"ENABLED","variants":{"v":{"title":"Hi","style":{},"items":[{"0":"x"}]}},"defaultVariant":"v"},
        "paused":{"state":"DISABLED","variants":{"on":true,"off":false},"defaultVariant":"on"}
    },"metadata":{"team":"core"}}');

    return new FeatureFlags(
        new FireflyFlagProvider($document, $evaluator ?? new StaticFlagdEvaluator),
        $context ?? new EvaluationContextResolver,
        $hooks,
        $document,
        $api ?? OpenFeatureAPIFactory::createAPI(),
    );
}

it('reads every type through the firefly client', function (): void {
    $flags = featureFlagsFacade();

    expect($flags->isEnabled('kill-switch'))->toBeTrue()
        ->and($flags->isEnabled('paused', true))->toBeTrue()
        ->and($flags->isEnabled('missing'))->toBeFalse()
        ->and($flags->getString('flow', 'z'))->toBe('B')
        ->and($flags->getInt('size', 0))->toBe(50)
        ->and($flags->getFloat('ratio', 1.0))->toBe(0.05)
        ->and($flags->getObject('banner'))->toBe(['title' => 'Hi'])
        ->and($flags->client()->getMetadata()->getName())->toBe(FeatureFlags::CLIENT_NAME)
        ->and($flags->provider())->toBeInstanceOf(FireflyFlagProvider::class);
});

it('evaluates each typed getter with its own type, never coercing around a type mismatch', function (): void {
    $flags = featureFlagsFacade();

    // An int default widens to a float parameter: the evaluation is a float one.
    expect($flags->getFloat('size', 1))->toBe(50.0)
        // A boolean is never a number, and a number is never a boolean: TYPE_MISMATCH answers the default.
        ->and($flags->getInt('kill-switch', 7))->toBe(7)
        ->and($flags->getFloat('kill-switch', 0.5))->toBe(0.5)
        ->and($flags->isEnabled('size'))->toBeFalse()
        ->and($flags->getInt('ratio', 3))->toBe(3)
        ->and($flags->getString('size', 'z'))->toBe('z')
        ->and($flags->getObject('flow', ['x' => 1]))->toBe(['x' => 1])
        ->and($flags->details('kill-switch', 0)->errorCode)->toBe('TYPE_MISMATCH')
        ->and($flags->details('size', 1.5)->value)->toBe(50.0);
});

it('details an evaluation with merged metadata, and none on an error', function (): void {
    $flags = featureFlagsFacade();

    expect($flags->details('kill-switch', false)->toArray())->toEqual([
        'key' => 'kill-switch', 'value' => true, 'variant' => 'on', 'reason' => 'STATIC', 'errorCode' => null,
        'metadata' => ['team' => 'core', 'owner' => 'ops'],
    ])
        ->and($flags->details('flow', false)->errorCode)->toBe('TYPE_MISMATCH')
        ->and($flags->details('flow', false)->reason)->toBe('ERROR')
        ->and($flags->details('flow', false)->variant)->toBeNull()
        ->and($flags->details('flow', false)->toArray()['metadata'])->toEqual(new stdClass)
        ->and($flags->details('paused', true)->toArray())->toEqual([
            'key' => 'paused', 'value' => true, 'variant' => null, 'reason' => 'DISABLED', 'errorCode' => null,
            'metadata' => ['team' => 'core'],
        ]);
});

it('resolves the variant with the flag\'s own type', function (): void {
    $flags = featureFlagsFacade();

    expect($flags->variant('size'))->toBe('l')
        ->and($flags->variant('ratio'))->toBe('low')
        ->and($flags->variant('kill-switch'))->toBe('on')
        ->and($flags->variant('banner'))->toBe('plain')
        ->and($flags->variant('paused'))->toBeNull()
        ->and($flags->variant('missing'))->toBeNull();
});

it('re-asserts its own provider on the API before evaluating', function (): void {
    $api = OpenFeatureAPIFactory::createAPI();
    $flags = featureFlagsFacade($api);
    $api->setProvider(new NoOpProvider);

    expect($flags->isEnabled('kill-switch'))->toBeTrue()
        ->and($api->getProvider())->toBeInstanceOf(FireflyFlagProvider::class);
});

it('attaches its hooks to the firefly client once', function (): void {
    $hook = new RecordingHook;
    $flags = featureFlagsFacade(hooks: [$hook]);

    $flags->isEnabled('kill-switch');
    $flags->getInt('size', 0);

    expect($flags->client())->toBe($flags->client())
        ->and($flags->client()->getHooks())->toBe([$hook])
        ->and(array_column($hook->evaluations, 'flag'))->toBe(['kill-switch', 'size']);
});

it('evaluates with the ambient context, the caller\'s context over it, through the client', function (): void {
    $evaluator = new StaticFlagdEvaluator;
    $principal = new class implements EvaluationContextContributor
    {
        public function contribute(EvaluationContextBuilder $context): void
        {
            $context->setTargetingKey('ada')->set('roles', ['beta'])->set('tenant', 'acme');
        }
    };
    $flags = featureFlagsFacade(context: new EvaluationContextResolver([$principal]), hooks: [new RecordingHook], evaluator: $evaluator);
    $renewal = new DateTimeImmutable('2025-06-30T23:59:59.999999-05:00');

    /** @var array<string, mixed> $explicit */
    $explicit = json_decode('{"2024":"leap","plan":"pro","tenant":"globex"}', true, 512, JSON_THROW_ON_ERROR);
    $explicit['renewedAt'] = $renewal;

    expect($flags->isEnabled('kill-switch', false, $explicit))->toBeTrue()
        ->and($evaluator->lastTargetingKey)->toBe('ada')
        ->and(array_keys($evaluator->lastAttributes))->toBe(['roles', 'tenant', 'plan', 'renewedAt'])
        ->and($evaluator->lastAttributes['tenant'] ?? null)->toBe('globex');

    $renewed = $evaluator->lastAttributes['renewedAt'] ?? null;
    assert($renewed instanceof DateTimeInterface);

    expect($renewed->format('Y-m-d\TH:i:s.uP'))->toBe('2025-06-30T23:59:59.999999-05:00')
        ->and($flags->getString('flow', 'z', [], 'grace'))->toBe('B')
        ->and($evaluator->lastTargetingKey)->toBe('grace');
});

it('previews without the caller: only the explicit context and the process attributes, marked for the hooks', function (): void {
    ProfileVariables::absent(function (): void {
        $evaluator = new StaticFlagdEvaluator;
        $hook = new RecordingHook;
        $principal = new class implements EvaluationContextContributor
        {
            public int $runs = 0;

            public function contribute(EvaluationContextBuilder $context): void
            {
                $this->runs++;
                $context->setTargetingKey('ada')->set('roles', ['admin'])->set('tenant', 'acme');
            }
        };
        $application = new ApplicationEvaluationContextContributor(new Repository([
            'app' => ['name' => 'orders'],
            'firefly' => ['profiles' => ['active' => 'eu']],
        ]));
        $flags = featureFlagsFacade(context: new EvaluationContextResolver([$application, $principal]), hooks: [$hook], evaluator: $evaluator);

        $preview = $flags->details('kill-switch', false, ['plan' => 'pro'], 'grace', ambient: false);

        expect($preview->value)->toBeTrue()
            ->and($evaluator->lastTargetingKey)->toBe('grace')
            ->and($evaluator->lastAttributes)->toBe(['application' => 'orders', 'profiles' => ['eu'], 'plan' => 'pro'])
            ->and($principal->runs)->toBe(0)
            ->and($hook->evaluations)->toBe([['flag' => 'kill-switch', 'reason' => 'STATIC', 'preview' => true]]);

        $flags->details('kill-switch', false);

        expect($evaluator->lastTargetingKey)->toBe('ada')
            ->and($principal->runs)->toBe(1)
            ->and($hook->evaluations[1] ?? null)->toBe(['flag' => 'kill-switch', 'reason' => 'STATIC', 'preview' => null]);
    });
});

it('hands callers plain arrays, never an instance shared with the stored definition', function (): void {
    $flags = featureFlagsFacade();
    $expected = ['title' => 'Hi', 'style' => [], 'items' => [['x']]];

    // toBe is ===: a stdClass anywhere in the value (the definition's own `{}` or `{"0": …}`) would fail it.
    expect($flags->getObject('panel'))->toBe($expected)
        ->and($flags->details('panel', [])->value)->toBe($expected)
        ->and($flags->client()->getObjectValue('panel', []))->toBe($expected);
});

it('builds a FlagEvaluation that owns its value, with metadata names kept as JSON object members', function (): void {
    $document = StaticDocument::json('{"flags":{
        "listy":{"state":"ENABLED","variants":{"v":{"0":"zero","1":[{"0":"deep"}]}},"defaultVariant":"v","metadata":{"0":"zero","1":"one"}},
        "dated":{"state":"ENABLED","variants":{"on":true},"defaultVariant":"on","metadata":{"2024":"leap"}}
    }}');
    $raw = (new StaticFlagdEvaluator)->evaluate($document->document, 'listy', FlagType::Object, []);

    $evaluation = FlagEvaluation::fromResolution('listy', $raw);
    /** @var stdClass $value */
    $value = $evaluation->value;
    $value->{'0'} = 'changed';
    /** @var list<stdClass> $nested */
    $nested = $value->{'1'};
    $nested[0]->{'0'} = 'changed';

    $dated = FlagEvaluation::fromResolution('dated', (new StaticFlagdEvaluator)->evaluate($document->document, 'dated', FlagType::Boolean, false));

    expect(Json::encode($raw->value))->toBe('{"0":"zero","1":[{"0":"deep"}]}')
        ->and(Json::encode((new StaticFlagdEvaluator)->evaluate($document->document, 'listy', FlagType::Object, [])->value))->toBe('{"0":"zero","1":[{"0":"deep"}]}')
        ->and(Json::encode(FlagEvaluation::fromResolution('listy', $raw)->toArray()))
        ->toBe('{"key":"listy","value":{"0":"zero","1":[{"0":"deep"}]},"variant":"v","reason":"STATIC","errorCode":null,"metadata":{"0":"zero","1":"one"}}')
        ->and(Json::encode($dated->toArray()['metadata']))->toBe('{"2024":"leap"}')
        ->and($dated->metadata)->toBe([2024 => 'leap']);
});
