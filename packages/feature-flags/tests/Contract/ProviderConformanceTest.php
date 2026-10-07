<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Definition\FlagDefinitions;
use Firefly\FeatureFlags\Definition\FlagDocument;
use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Evaluation\DefaultFlagdEvaluator;
use Firefly\FeatureFlags\Provider\FireflyFlagProvider;
use Firefly\FeatureFlags\Tests\Support\ConformanceFiles;
use Firefly\FeatureFlags\Tests\Support\FireflyVectors;
use Firefly\FeatureFlags\Tests\Support\StaticDocument;
use OpenFeature\implementation\flags\Attributes;
use OpenFeature\implementation\flags\EvaluationContext;
use OpenFeature\isolated\OpenFeatureAPIFactory;

it('answers every evaluate vector identically through an OpenFeature client', function (array $case): void {
    $expect = Json::members($case['expect']);
    $api = OpenFeatureAPIFactory::createAPI();
    $api->setProvider(new FireflyFlagProvider(new StaticDocument(FlagDocument::fromJsonValue($case['document'])), new DefaultFlagdEvaluator));
    $client = $api->getClient('conformance', 'test');
    $targetingKey = $case['targetingKey'] ?? null;
    /** @var array<string, bool|string|int|float|array<mixed>|null> $attributes */
    $attributes = Json::toPhp(Json::members($case['context'] ?? null));
    $context = new EvaluationContext(is_string($targetingKey) ? $targetingKey : null, new Attributes($attributes));
    $flag = $case['flag'];
    $type = $case['type'];
    assert(is_string($flag) && is_string($type));
    $default = Json::toPhp($case['default']);

    $details = match ($type) {
        'boolean' => $client->getBooleanDetails($flag, (bool) $default, $context),
        'string' => $client->getStringDetails($flag, is_string($default) ? $default : '', $context),
        'integer' => $client->getIntegerDetails($flag, is_int($default) ? $default : 0, $context),
        'float' => $client->getFloatDetails($flag, is_float($default) || is_int($default) ? (float) $default : 0.0, $context),
        default => $client->getObjectDetails($flag, is_array($default) ? $default : [], $context),
    };

    expect(Json::canonical($details->getValue()))->toBe(Json::canonical(Json::toPhp($expect['value'] ?? null)))
        ->and($details->getVariant())->toBe($expect['variant'] ?? null)
        ->and($details->getReason())->toBe($expect['reason'] ?? null)
        ->and($details->getError()?->getResolutionErrorCode()->getValue())->toBe($expect['errorCode'] ?? null);
})->with(fn (): array => FireflyVectors::cases('evaluate'));

it('accepts the testbed document under Firefly\'s own validation rules', function (): void {
    $raw = Json::decode((string) file_get_contents(ConformanceFiles::root().'/testbed/evaluator/flags/testkit-flags.json'));

    expect(FlagDefinitions::parseDocument($raw)->keys())->toEqual(ConformanceFiles::testkitDocument()->keys());
});
