<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Definition\FlagDocument;
use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Evaluation\EvaluationError;
use Firefly\FeatureFlags\Evaluation\EvaluationReason;
use Firefly\FeatureFlags\Evaluation\FlagdEvaluator;
use Firefly\FeatureFlags\Evaluation\FlagType;
use Firefly\FeatureFlags\Evaluation\Resolution;
use Firefly\FeatureFlags\Provider\FireflyFlagProvider;
use Firefly\FeatureFlags\Tests\Support\RecordingLogger;
use Firefly\FeatureFlags\Tests\Support\StaticDocument;
use Firefly\FeatureFlags\Tests\Support\StaticFlagdEvaluator;
use OpenFeature\implementation\flags\Attributes;
use OpenFeature\implementation\flags\EvaluationContext;

function featureFlagsProvider(?StaticDocument $document = null, ?FlagdEvaluator $evaluator = null): FireflyFlagProvider
{
    return new FireflyFlagProvider(
        $document ?? StaticDocument::json('{"flags":{"on":{"state":"ENABLED","variants":{"on":true,"off":false},"defaultVariant":"on","metadata":{"owner":"web"}},"size":{"state":"ENABLED","variants":{"s":10},"defaultVariant":"s"},"banner":{"state":"ENABLED","variants":{"none":{}},"defaultVariant":"none"}}}'),
        $evaluator ?? new StaticFlagdEvaluator,
    );
}

it('names itself firefly', function (): void {
    expect(featureFlagsProvider()->getMetadata()->getName())->toBe('firefly')
        ->and(FireflyFlagProvider::NAME)->toBe('firefly');
});

it('resolves each OpenFeature type with variant and reason', function (): void {
    $provider = featureFlagsProvider();
    $context = new EvaluationContext('u-1', new Attributes(['roles' => ['beta']]));

    $boolean = $provider->resolveBooleanValue('on', false, $context);
    $missing = $provider->resolveStringValue('missing', 'dflt');

    expect($boolean->getValue())->toBeTrue()
        ->and($boolean->getVariant())->toBe('on')
        ->and($boolean->getReason())->toBe('STATIC')
        ->and($provider->resolveIntegerValue('size', 0)->getValue())->toBe(10)
        ->and($provider->resolveFloatValue('size', 0.0)->getValue())->toBe(10.0)
        ->and($provider->resolveObjectValue('banner', ['x' => 1])->getValue())->toBe([])
        ->and($missing->getValue())->toBe('dflt')
        ->and($missing->getVariant())->toBeNull()
        ->and($missing->getReason())->toBe('ERROR')
        ->and($missing->getError()?->getResolutionErrorCode()->getValue())->toBe('FLAG_NOT_FOUND');
});

it('keeps the metadata on its own resolution', function (): void {
    expect(featureFlagsProvider()->resolution('on', FlagType::Boolean, false)->metadata)->toBe(['owner' => 'web']);
});

it('hands the evaluator the context\'s targeting key and attributes as they are', function (): void {
    $evaluator = new StaticFlagdEvaluator;
    $signup = new DateTime('2025-01-01T10:00:00.123456+02:00');

    featureFlagsProvider(evaluator: $evaluator)->resolveBooleanValue('on', false, new EvaluationContext('u-1', new Attributes(['roles' => ['beta'], 'signupAt' => $signup])));

    expect($evaluator->lastTargetingKey)->toBe('u-1')
        ->and($evaluator->lastAttributes)->toBe(['roles' => ['beta'], 'signupAt' => $signup]);

    featureFlagsProvider(evaluator: $evaluator)->resolveBooleanValue('on', false);

    expect($evaluator->lastTargetingKey)->toBeNull()
        ->and($evaluator->lastAttributes)->toBe([]);
});

it('never throws: an unreadable flag set is a GENERAL error carrying the default', function (): void {
    $document = StaticDocument::json('{"flags":{}}');
    $document->broken = true;

    $resolution = featureFlagsProvider($document)->resolution('on', FlagType::Boolean, true);

    expect($resolution->value)->toBeTrue()
        ->and($resolution->reason)->toBe(EvaluationReason::Error)
        ->and($resolution->error)->toBe(EvaluationError::General)
        ->and($resolution->errorMessage)->toContain('the cache is on fire');
});

it('never throws: an evaluator that throws is a GENERAL error carrying the default, logged at DEBUG', function (): void {
    $logger = new RecordingLogger;
    $provider = featureFlagsProvider(evaluator: new class implements FlagdEvaluator
    {
        public function evaluate(FlagDocument $document, string $flagKey, FlagType $type, mixed $default, ?string $targetingKey = null, array $attributes = []): Resolution
        {
            throw new LogicException('the evaluator broke');
        }
    });
    $provider->setLogger($logger);

    $details = $provider->resolveStringValue('on', 'fallback');

    expect($details->getValue())->toBe('fallback')
        ->and($details->getError()?->getResolutionErrorCode()->getValue())->toBe('GENERAL')
        ->and($details->getError()?->getResolutionErrorMessage())->toContain('the evaluator broke')
        ->and($logger->count('debug', 'Feature flag [on] evaluated with GENERAL'))->toBe(1);
});

it('hands out a deep copy of an object value, so a caller mutating it never reaches the stored definition', function (): void {
    $document = StaticDocument::json('{"flags":{
        "listy":{"state":"ENABLED","variants":{"v":{"0":"zero","1":[{"0":"deep"}]}},"defaultVariant":"v"},
        "panel":{"state":"ENABLED","variants":{"v":{"title":"Hi","style":{},"items":[{"0":"x"}]}},"defaultVariant":"v"}
    }}');
    $stored = $document->document->toJson();
    $provider = featureFlagsProvider($document);

    // A list-like object stays a stdClass in the faithful form: mutate a property and a nested list.
    /** @var stdClass $listy */
    $listy = $provider->resolution('listy', FlagType::Object, [])->value;
    $listy->{'0'} = 'changed';
    /** @var list<stdClass> $nested */
    $nested = $listy->{'1'};
    $nested[0]->{'0'} = 'changed';
    $nested[] = 'appended';
    $listy->{'1'} = $nested;

    // An ordinary object is an array whose empty and list-like members are stdClass instances.
    /** @var array{title: string, style: stdClass, items: list<stdClass>} $panel */
    $panel = $provider->resolution('panel', FlagType::Object, [])->value;
    $panel['style']->color = 'red';
    $panel['items'][0]->{'0'} = 'changed';

    // The SDK's view holds plain arrays only (no shared instance to reach the definition through).
    expect(Json::encode($provider->resolution('listy', FlagType::Object, [])->value))->toBe('{"0":"zero","1":[{"0":"deep"}]}')
        ->and(Json::encode($provider->resolution('panel', FlagType::Object, [])->value))->toBe('{"title":"Hi","style":{},"items":[{"0":"x"}]}')
        ->and($provider->resolveObjectValue('panel', [])->getValue())->toBe(['title' => 'Hi', 'style' => [], 'items' => [['x']]])
        ->and($document->document->toJson())->toBe($stored);
});
