<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Definition\FlagDefinitions;
use Firefly\FeatureFlags\Definition\FlagDocument;
use Firefly\FeatureFlags\Definition\InvalidFlagDefinition;
use Firefly\FeatureFlags\Definition\Json;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * The error parseDocument() raises for $document, or null when the document loads.
 */
function featureFlagsRefusal(mixed $document): ?InvalidFlagDefinition
{
    try {
        FlagDefinitions::parseDocument($document);
    } catch (InvalidFlagDefinition $caught) {
        return $caught;
    }

    return null;
}

/**
 * A valid boolean flag with $fields merged over it.
 *
 * @param  array<string, mixed>  $fields
 * @return array<string, mixed>
 */
function featureFlagsBoolFlag(array $fields = []): array
{
    return [...['state' => 'ENABLED', 'variants' => ['on' => true, 'off' => false], 'defaultVariant' => 'off'], ...$fields];
}

it('keeps a numeric-looking string shorthand a single-variant OBJECT', function (): void {
    $normalized = FlagDefinitions::normalize(['legacy' => '0']);

    expect(Json::encode($normalized))->toBe('{"legacy":{"state":"ENABLED","variants":{"0":"0"},"defaultVariant":"0"}}');
});

it('accepts a numeric-looking flag key and keeps it a string', function (): void {
    $document = FlagDefinitions::parseDocument(['flags' => Json::object(FlagDefinitions::normalize(['2024' => true]))]);

    expect($document->keys())->toBe(['2024'])
        ->and($document->flag('2024')?->defaultVariant())->toBe('on');
});

it('refuses a boolean defaultVariant, even beside variants named "true" and "false" (R-default-variant-bool)', function (): void {
    $error = featureFlagsRefusal(['flags' => ['b' => ['state' => 'ENABLED', 'variants' => ['true' => 1, 'false' => 0], 'defaultVariant' => false]]]);

    expect($error?->flagKey())->toBe('b')
        ->and($error?->reason())->toStartWith('defaultVariant is not a variant');
});

it('accepts a defaultVariant only when it is the text name of a variant', function (array|stdClass $variants, mixed $default, bool $valid): void {
    $error = featureFlagsRefusal(['flags' => ['d' => ['state' => 'ENABLED', 'variants' => $variants, 'defaultVariant' => $default]]]);

    expect($error === null)->toBe($valid)
        ->and($error?->reason() ?? 'defaultVariant is not a variant')->toStartWith('defaultVariant is not a variant');
})->with([
    'empty text naming no variant' => [['on' => true, 'off' => false], '', false],
    'empty text naming the "" variant' => [['' => true, 'off' => false], '', true],
    'a number naming variant "1"' => [['1' => 'one', '2' => 'two'], 1, false],
    'the text "1" naming variant "1"' => [['1' => 'one', '2' => 'two'], '1', true],
    'the text "0" naming variant "0" of a list-like object' => [Json::object(['zero', 'one']), '0', true],
    'an array' => [['on' => true, 'off' => false], ['on'], false],
    'null' => [['on' => true, 'off' => false], null, true],
]);

it('refuses a null variant value as a type mix', function (): void {
    expect(fn () => FlagDefinitions::parseDocument(['flags' => ['n' => ['state' => 'ENABLED', 'variants' => ['a' => null]]]]))
        ->toThrow(InvalidFlagDefinition::class, 'variants must share one type');
});

it('refuses document-level rules, naming the section that broke one', function (mixed $document, string $key, string $reason): void {
    $error = featureFlagsRefusal($document);

    expect($error?->flagKey())->toBe($key)
        ->and($error?->reason())->toBe($reason);
})->with([
    'document not an object' => [[1, 2], '', 'flag document must be an object'],
    'flags a list' => [['flags' => [1, 2]], 'flags', 'flags must be an object'],
    'flags false' => [['flags' => false], 'flags', 'flags must be an object'],
    'flags text' => [['flags' => 'a'], 'flags', 'flags must be an object'],
    'a flag not an object' => [['flags' => ['x' => 42]], 'x', 'flag definition must be an object'],
    'evaluators a list' => [['$evaluators' => ['x']], '$evaluators', '$evaluators must be an object'],
    'evaluators zero' => [['$evaluators' => 0], '$evaluators', '$evaluators must be an object'],
    'an evaluator not an object' => [['$evaluators' => ['beta' => 'yes']], '$evaluators.beta', 'an evaluator must be an object'],
    'document metadata text' => [['metadata' => 'x'], 'metadata', 'metadata must be an object'],
    'document metadata a list' => [['metadata' => ['a']], 'metadata', 'metadata must be an object'],
    'document metadata not scalar' => [['flags' => new stdClass, 'metadata' => ['tags' => ['a']]], 'metadata', 'metadata values must be scalars'],
    'document metadata null value' => [['metadata' => ['owner' => null]], 'metadata', 'metadata values must be scalars'],
    'document metadata empty key' => [Json::decode('{"metadata":{"":"x"}}'), 'metadata', 'metadata keys must not be empty'],
]);

it('reads an absent or null section as empty', function (string $section): void {
    $document = FlagDefinitions::parseDocument([$section => null]);

    expect($document->flags)->toBe([])
        ->and($document->evaluators)->toBe([])
        ->and($document->metadata)->toBe([]);
})->with(['flags', '$evaluators', 'metadata']);

it('reads numeric-looking metadata keys and evaluator names as text, never "not strings" (RF3)', function (): void {
    $document = FlagDefinitions::parseDocument(Json::decode(
        '{"flags":{"m":{"state":"ENABLED","variants":{"on":true},"defaultVariant":"on","metadata":{"1":"one","2024":true}}},'
        .'"$evaluators":{"7":{"var":"a"}},"metadata":{"2":"x"}}',
    ));

    expect(array_map(static fn (int|string $name): string => (string) $name, array_keys($document->flag('m')?->metadata() ?? [])))->toBe(['1', '2024'])
        ->and(array_map(static fn (int|string $name): string => (string) $name, array_keys($document->evaluators)))->toBe(['7'])
        ->and(array_map(static fn (int|string $name): string => (string) $name, array_keys($document->metadata)))->toBe(['2']);
});

it('never meets a YAML key that is not text: Symfony Yaml refuses one when it parses, and reads on: as text', function (): void {
    expect(fn () => Yaml::parse("flags: {}\nmetadata:\n  true: x\n", Yaml::PARSE_OBJECT_FOR_MAP))->toThrow(ParseException::class)
        ->and(fn () => Yaml::parse("flags: {}\n\$evaluators:\n  null: {var: a}\n", Yaml::PARSE_OBJECT_FOR_MAP))->toThrow(ParseException::class);

    $document = FlagDefinitions::parseDocument(Json::normalize(Yaml::parse(
        "flags:\n  m: {state: ENABLED, variants: {'on': true}, defaultVariant: 'on', metadata: {on: x}}\n",
        Yaml::PARSE_OBJECT_FOR_MAP,
    )));

    expect($document->flag('m')?->metadata())->toBe(['on' => 'x']);
});

it('refuses a number that is not finite anywhere in a definition, before composition could encode it', function (mixed $document, string $key, string $reason): void {
    $error = featureFlagsRefusal($document);

    expect($error?->flagKey())->toBe($key)
        ->and($error?->reason())->toBe($reason);
})->with([
    'a variant' => [['flags' => ['n' => ['state' => 'ENABLED', 'variants' => ['a' => NAN, 'b' => 1.5], 'defaultVariant' => 'a']]], 'n', 'numbers must be finite'],
    'targeting' => [['flags' => ['t' => featureFlagsBoolFlag(['targeting' => ['<' => [['var' => 'x'], INF]]])]], 't', 'numbers must be finite'],
    'flag metadata' => [['flags' => ['m' => featureFlagsBoolFlag(['metadata' => ['rate' => NAN]])]], 'm', 'numbers must be finite'],
    'a field flagd does not define' => [['flags' => ['u' => featureFlagsBoolFlag(['notes' => ['weight' => -INF]])]], 'u', 'numbers must be finite'],
    'an evaluator' => [['$evaluators' => ['ok' => ['var' => 'a'], 'big' => ['>' => [['var' => 'n'], -INF]]]], '$evaluators', "numbers must be finite (evaluator 'big')"],
    'document metadata' => [['metadata' => ['rate' => INF]], 'metadata', 'numbers must be finite'],
    'YAML .nan' => [Json::normalize(Yaml::parse('flags: {y: {state: ENABLED, variants: {a: .nan, b: 1.5}, defaultVariant: a}}', Yaml::PARSE_OBJECT_FOR_MAP)), 'y', 'numbers must be finite'],
    'YAML .inf in document metadata' => [Json::normalize(Yaml::parse("flags: {}\nmetadata: {rate: .inf}", Yaml::PARSE_OBJECT_FOR_MAP)), 'metadata', 'numbers must be finite'],
]);

it('walks a definition nested far deeper than JSON allows without recursing, and finds what lies at the bottom', function (): void {
    $finiteTree = [1.5];
    $infiniteTree = [INF];
    for ($level = 0; $level < 5000; $level++) {
        $finiteTree = ['x' => [$finiteTree]];
        $infiniteTree = ['x' => [$infiniteTree]];
    }

    $finite = featureFlagsRefusal(['flags' => ['deep' => featureFlagsBoolFlag(['targeting' => ['<' => [['var' => 'x'], $finiteTree]]])]]);
    $infinite = featureFlagsRefusal(['flags' => ['deep' => featureFlagsBoolFlag(['targeting' => ['<' => [['var' => 'x'], $infiniteTree]]])]]);

    expect($finite)->toBeNull()
        ->and($infinite?->reason())->toBe('numbers must be finite');
});

it('reports the first broken rule in the contract order PyFly checks', function (mixed $definition, string $reason): void {
    expect(featureFlagsRefusal(['flags' => ['k' => $definition]])?->reason())->toBe($reason);
})->with([
    'object before state' => ['ENABLED', 'flag definition must be an object'],
    'state before variants' => [['state' => 'ON', 'variants' => []], 'state must be ENABLED or DISABLED'],
    'variants a scalar' => [['state' => 'ENABLED', 'variants' => 'on'], 'variants must be a non-empty object'],
    'defaultVariant before targeting' => [featureFlagsBoolFlag(['defaultVariant' => 'maybe', 'targeting' => 'x']), 'defaultVariant is not a variant'],
    'targeting text' => [featureFlagsBoolFlag(['targeting' => 'x']), 'targeting must be an object'],
    'flag metadata not an object' => [featureFlagsBoolFlag(['metadata' => 'x']), 'metadata values must be scalars'],
    'flag metadata a list' => [featureFlagsBoolFlag(['metadata' => ['a']]), 'metadata values must be scalars'],
    'scalars before keys' => [featureFlagsBoolFlag(['metadata' => Json::decode('{"":"x","tags":["a"]}')]), 'metadata values must be scalars'],
    'keys before kind' => [featureFlagsBoolFlag(['metadata' => Json::decode('{"":"x","kind":"temporary"}')]), 'metadata keys must not be empty'],
    'kind before expires' => [featureFlagsBoolFlag(['metadata' => ['kind' => 'x', 'expires' => 'soon', 'owner' => 1, 'description' => 2]]), 'kind must be one of release, experiment, ops, permission'],
    'expires before owner' => [featureFlagsBoolFlag(['metadata' => ['expires' => 'soon', 'owner' => 1, 'description' => 2]]), 'expires must be a YYYY-MM-DD date'],
    'owner before description' => [featureFlagsBoolFlag(['metadata' => ['owner' => 1, 'description' => 2]]), 'owner must be a string'],
    'finite numbers last' => [featureFlagsBoolFlag(['variants' => ['a' => INF], 'defaultVariant' => 'a', 'metadata' => ['kind' => 'x']]), 'kind must be one of release, experiment, ops, permission'],
]);

it('refuses a whole document when one flag breaks a rule', function (): void {
    $error = featureFlagsRefusal(['flags' => ['good' => featureFlagsBoolFlag(), 'bad key' => featureFlagsBoolFlag()]]);

    expect($error?->flagKey())->toBe('bad key')
        ->and($error?->getMessage())->toBe('Invalid feature flag [bad key]: invalid flag key.');
});

it('accepts a $ref to a missing evaluator: evaluation reports it, loading does not', function (): void {
    $document = FlagDefinitions::parseDocument(['flags' => ['r' => [
        'state' => 'ENABLED', 'variants' => ['on' => true, 'off' => false], 'defaultVariant' => 'off',
        'targeting' => ['if' => [['$ref' => 'nope'], 'on', null]],
    ]]]);

    expect($document->keys())->toBe(['r']);
});

it('accepts and keeps a field flagd does not define: unknown fields are ignored, never errors (RF6)', function (): void {
    $document = FlagDefinitions::parseDocument(Json::decode('{"flags":{"described":{"state":"ENABLED","description":"outside metadata","rollout":{"owner":"x"},"variants":{"on":true},"defaultVariant":"on"}}}'));

    expect($document->keys())->toBe(['described'])
        ->and($document->flag('described')?->toArray()['description'] ?? null)->toBe('outside metadata');
});

it('keeps the evaluators and the document metadata it validated', function (): void {
    $document = FlagDefinitions::parseDocument(['flags' => new stdClass, '$evaluators' => ['beta' => ['in' => ['beta', ['var' => 'roles']]]], 'metadata' => ['team' => 'web', 'rank' => 2]]);

    expect($document)->toBeInstanceOf(FlagDocument::class)
        ->and($document->evaluators)->toBe(['beta' => ['in' => ['beta', ['var' => 'roles']]]])
        ->and($document->metadata)->toBe(['team' => 'web', 'rank' => 2]);
});

it('lists expired keys as text, sorted as text', function (): void {
    $document = FlagDefinitions::parseDocument(['flags' => [
        '2024' => featureFlagsBoolFlag(['metadata' => ['expires' => '2025-01-01']]),
        '10' => featureFlagsBoolFlag(['metadata' => ['expires' => '2025-01-01']]),
        'later' => featureFlagsBoolFlag(['metadata' => ['expires' => '2099-12-31']]),
    ]]);

    expect(FlagDefinitions::expiredKeys($document->flags, '2026-10-01'))->toBe(['10', '2024']);
});

it('names the source in the message once the registry attaches it', function (): void {
    $error = (new InvalidFlagDefinition('bad key', 'invalid flag key'))->fromSource('config');

    expect($error->getMessage())->toBe('Invalid feature flag [bad key] from source [config]: invalid flag key.')
        ->and($error->source())->toBe('config')
        ->and($error->errorCode())->toBe('INVALID_FEATURE_FLAG');
});
