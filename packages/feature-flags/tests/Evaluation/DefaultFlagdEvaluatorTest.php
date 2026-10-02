<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Definition\FlagDocument;
use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Evaluation\DefaultFlagdEvaluator;
use Firefly\FeatureFlags\Evaluation\EvaluationError;
use Firefly\FeatureFlags\Evaluation\EvaluationReason;
use Firefly\FeatureFlags\Evaluation\FlagType;
use Firefly\FeatureFlags\Evaluation\RefResolver;
use Firefly\FeatureFlags\Evaluation\Resolution;

/*
 | Every expectation here is what the reference evaluator (openfeature-flagd-core 1.0.0) answers for the same
 | document, flag, type, default and context once the document's `$ref`s are expanded the contract's way — except
 | where a contract ruling departs from it on purpose, which the test says.
 */

function featureFlagsEvaluatorDocument(): FlagDocument
{
    return FlagDocument::fromJson('{
        "flags": {
            "2024": {"state": "ENABLED", "variants": {"0": "zero", "1": "one"}, "defaultVariant": "0",
                     "targeting": {"if": [{"==": [{"var": "tier"}, "gold"]}, 1, null]}},
            "named-by-bool": {"state": "ENABLED", "variants": {"true": "yes", "false": "no"}, "defaultVariant": "false",
                              "targeting": {"in": ["beta", {"var": "roles"}]}},
            "empty-object": {"state": "ENABLED", "variants": {"empty": {}, "full": {"k": 1}}, "defaultVariant": "empty"},
            "ints": {"state": "ENABLED", "variants": {"small": 10, "large": 50}, "defaultVariant": "small"},
            "chained": {"state": "ENABLED", "variants": {"on": true, "off": false}, "defaultVariant": "off",
                        "targeting": {"if": [{"$ref": "staff-beta"}, "on", null]}},
            "chained-backwards": {"state": "ENABLED", "variants": {"on": true, "off": false}, "defaultVariant": "off",
                                  "targeting": {"if": [{"$ref": "a-alias"}, "on", null]}},
            "cyclic": {"state": "ENABLED", "variants": {"on": true, "off": false}, "defaultVariant": "off",
                       "targeting": {"if": [{"$ref": "a"}, "on", null]}},
            "dangling": {"state": "ENABLED", "variants": {"on": true, "off": false}, "defaultVariant": "off",
                         "targeting": {"if": [{"$ref": "nobody"}, "on", null]}},
            "dangling-untaken": {"state": "ENABLED", "variants": {"on": true, "off": false}, "defaultVariant": "off",
                                 "targeting": {"if": [true, "on", {"$ref": "nobody"}]}},
            "stamped": {"state": "ENABLED", "variants": {"late": "late", "early": "early"}, "defaultVariant": "early",
                        "targeting": {"if": [{">": [{"var": "$flagd.timestamp"}, 1790000000]}, "late", null]}},
            "who": {"state": "ENABLED", "variants": {"me": "me", "other": "other"}, "defaultVariant": "other",
                    "targeting": {"if": [{"==": [{"var": "targetingKey"}, "u-1"]}, "me", null]}},
            "self-named": {"state": "ENABLED", "variants": {"self-named": "mine", "other": "other"}, "defaultVariant": "other",
                           "targeting": {"var": "$flagd.flagKey"}}
        },
        "$evaluators": {
            "staff-beta": {"and": [{"$ref": "is-staff"}, {"in": ["beta", {"var": "roles"}]}]},
            "is-staff": {"in": ["staff", {"var": "roles"}]},
            "a-alias": {"$ref": "z-target"},
            "z-target": {"in": ["staff", {"var": "roles"}]},
            "a": {"$ref": "b"},
            "b": {"$ref": "a"}
        },
        "metadata": {"team": "web", "owner": "doc"}
    }');
}

/**
 * @param  array<array-key, mixed>  $flag
 * @param  array<array-key, mixed>  $document  the rest of the document
 */
function featureFlagsOneFlag(array $flag, array $document = []): FlagDocument
{
    return FlagDocument::fromJsonValue(['flags' => ['f' => $flag], ...$document]);
}

/**
 * A boolean flag that is "on" when the context attribute at $path equals $number.
 */
function featureFlagsEqualsFlag(string $path, int|float $number): FlagDocument
{
    return featureFlagsOneFlag([
        'state' => 'ENABLED', 'variants' => ['on' => true, 'off' => false], 'defaultVariant' => 'off',
        'targeting' => ['if' => [['==' => [['var' => $path], $number]], 'on', null]],
    ]);
}

/**
 * The float next to $value, one unit in the last place away from zero.
 */
function featureFlagsNeighbour(float $value): float
{
    /** @var array{1: int} $bits */
    $bits = unpack('q', pack('d', $value));
    /** @var array{1: float} $neighbour */
    $neighbour = unpack('d', pack('q', $bits[1] + 1));

    return $neighbour[1];
}

/**
 * @return list<mixed> value, variant, reason, error
 */
function featureFlagsOutcome(Resolution $resolution): array
{
    return [$resolution->value, $resolution->variant, $resolution->reason, $resolution->error];
}

it('evaluates numeric-looking flag keys and variant names as strings', function (): void {
    $evaluator = new DefaultFlagdEvaluator;
    $document = featureFlagsEvaluatorDocument();

    $gold = $evaluator->evaluate($document, '2024', FlagType::String, 'dflt', 'u1', ['tier' => 'gold']);
    $plain = $evaluator->evaluate($document, '2024', FlagType::String, 'dflt', 'u1');

    expect([$gold->value, $gold->variant, $gold->reason])->toBe(['one', '1', EvaluationReason::TargetingMatch])
        ->and([$plain->value, $plain->variant, $plain->reason])->toBe(['zero', '0', EvaluationReason::Default]);
});

it('names the "true" and "false" variants from a boolean targeting result', function (): void {
    $evaluator = new DefaultFlagdEvaluator;

    expect($evaluator->evaluate(featureFlagsEvaluatorDocument(), 'named-by-bool', FlagType::String, 'x', null, ['roles' => ['beta']])->variant)->toBe('true')
        ->and($evaluator->evaluate(featureFlagsEvaluatorDocument(), 'named-by-bool', FlagType::String, 'x', null, ['roles' => []])->variant)->toBe('false');
});

it('names a variant by the text Python writes for a number or a list the targeting selects', function (mixed $selected, string $name): void {
    $variants = [$name => 'picked', 'other' => 'other'];
    $document = featureFlagsOneFlag(['state' => 'ENABLED', 'variants' => $variants, 'defaultVariant' => 'other', 'targeting' => ['var' => 'selected']]);

    $resolution = (new DefaultFlagdEvaluator)->evaluate($document, 'f', FlagType::String, 'fallback', 'u', ['selected' => $selected]);

    expect(featureFlagsOutcome($resolution))->toBe(['picked', $name, EvaluationReason::TargetingMatch, null]);
})->with([
    'an integer' => [7, '7'],
    'a whole float keeps .0' => [2.0, '2.0'],
    'a float in its shortest digits' => [0.1 + 0.2, '0.30000000000000004'],
    'a large float as an exponent' => [1e16, '1e+16'],
    'a list as its repr' => [[1, 'a', true, null], "[1, 'a', True, None]"],
    'an object as its dict repr' => [['k' => "it's"], "{'k': \"it's\"}"],
]);

it('names a fractional bucket written as a number by its Python text', function (): void {
    $document = featureFlagsOneFlag([
        'state' => 'ENABLED', 'variants' => ['1' => 'one', '2.5' => 'two and a half'], 'defaultVariant' => '1',
        'targeting' => ['fractional' => [[1, 0], [2.5, 100]]],
    ]);

    $resolution = (new DefaultFlagdEvaluator)->evaluate($document, 'f', FlagType::String, 'fallback', 'any-user');

    expect(featureFlagsOutcome($resolution))->toBe(['two and a half', '2.5', EvaluationReason::TargetingMatch, null]);
});

it('keeps an empty object variant an object and hands OpenFeature an array', function (): void {
    $resolution = (new DefaultFlagdEvaluator)->evaluate(featureFlagsEvaluatorDocument(), 'empty-object', FlagType::Object, []);

    expect($resolution->value)->toBeInstanceOf(stdClass::class)
        ->and($resolution->toResolutionDetails()->getValue())->toBe([]);
});

it('returns an integer variant as a float for a float request and refuses it for a boolean one', function (): void {
    $evaluator = new DefaultFlagdEvaluator;

    expect($evaluator->evaluate(featureFlagsEvaluatorDocument(), 'ints', FlagType::Float, 1.0)->value)->toBe(10.0)
        ->and($evaluator->evaluate(featureFlagsEvaluatorDocument(), 'ints', FlagType::Boolean, false)->error)->toBe(EvaluationError::TypeMismatch);
});

it('checks the requested type exactly as flagd does (M6)', function (array $variants, FlagType $type, mixed $default, array $expected): void {
    $document = featureFlagsOneFlag(['state' => 'ENABLED', 'variants' => $variants, 'defaultVariant' => array_key_first($variants)]);

    expect(featureFlagsOutcome((new DefaultFlagdEvaluator)->evaluate($document, 'f', $type, $default)))->toBe($expected);
})->with([
    // Python's bool is an int, and a float request accepts an int: the reference returns float(True).
    'a float request on a boolean variant reads it as 1.0' => [['on' => true, 'off' => false], FlagType::Float, 0.5, [1.0, 'on', EvaluationReason::Static, null]],
    'a float request on a false variant reads it as 0.0' => [['off' => false, 'on' => true], FlagType::Float, 0.5, [0.0, 'off', EvaluationReason::Static, null]],
    'an integer request refuses a float variant' => [['low' => 0.5], FlagType::Integer, 3, [3, null, EvaluationReason::Error, EvaluationError::TypeMismatch]],
    'an integer request refuses a boolean variant' => [['on' => true], FlagType::Integer, 3, [3, null, EvaluationReason::Error, EvaluationError::TypeMismatch]],
    'a boolean request refuses an integer variant' => [['one' => 1], FlagType::Boolean, false, [false, null, EvaluationReason::Error, EvaluationError::TypeMismatch]],
    'a string request refuses a number' => [['one' => 1], FlagType::String, 'x', ['x', null, EvaluationReason::Error, EvaluationError::TypeMismatch]],
    'an object request accepts a list variant' => [['pair' => [1, 2]], FlagType::Object, [], [[1, 2], 'pair', EvaluationReason::Static, null]],
    'an object request refuses text' => [['t' => 'text'], FlagType::Object, [], [[], null, EvaluationReason::Error, EvaluationError::TypeMismatch]],
]);

it('converts the caller default of a float request and skips the type check where flagd does', function (): void {
    $evaluator = new DefaultFlagdEvaluator;
    $disabled = featureFlagsOneFlag(['state' => 'DISABLED', 'variants' => ['t' => 'text'], 'defaultVariant' => 't']);
    $noDefault = featureFlagsOneFlag(['state' => 'ENABLED', 'variants' => ['t' => 'text']]);

    expect(featureFlagsOutcome($evaluator->evaluate($disabled, 'f', FlagType::Float, 2)))->toBe([2.0, null, EvaluationReason::Disabled, null])
        ->and(featureFlagsOutcome($evaluator->evaluate($noDefault, 'f', FlagType::Float, 2)))->toBe([2.0, null, EvaluationReason::Default, null])
        ->and(featureFlagsOutcome($evaluator->evaluate($noDefault, 'f', FlagType::Boolean, true)))->toBe([true, null, EvaluationReason::Default, null]);
});

it('resolves an evaluator that references another one, whatever the names sort as', function (): void {
    $evaluator = new DefaultFlagdEvaluator;
    $chained = $evaluator->evaluate(featureFlagsEvaluatorDocument(), 'chained', FlagType::Boolean, false, 'u', ['roles' => ['staff', 'beta']]);
    $backwards = $evaluator->evaluate(featureFlagsEvaluatorDocument(), 'chained-backwards', FlagType::Boolean, false, 'u', ['roles' => ['staff']]);

    expect([$chained->value, $chained->reason])->toBe([true, EvaluationReason::TargetingMatch])
        ->and([$backwards->value, $backwards->reason])->toBe([true, EvaluationReason::TargetingMatch]);
});

it('stops a reference cycle and reports it as a parse error', function (): void {
    $resolution = (new DefaultFlagdEvaluator)->evaluate(featureFlagsEvaluatorDocument(), 'cyclic', FlagType::Boolean, true);

    expect([$resolution->value, $resolution->error])->toBe([true, EvaluationError::ParseError])
        ->and([RefResolver::MAX_VALUES, RefResolver::MAX_DEPTH])->toBe([10_000, 128]);
});

it('reports a reference to no evaluator as a parse error when the rule reaches it, as flagd does', function (): void {
    $evaluator = new DefaultFlagdEvaluator;

    expect(featureFlagsOutcome($evaluator->evaluate(featureFlagsEvaluatorDocument(), 'dangling', FlagType::Boolean, true)))
        ->toBe([true, null, EvaluationReason::Error, EvaluationError::ParseError])
        ->and(featureFlagsOutcome($evaluator->evaluate(featureFlagsEvaluatorDocument(), 'dangling-untaken', FlagType::Boolean, false)))
        ->toBe([true, 'on', EvaluationReason::TargetingMatch, null]);
});

it('resolves references only inside targeting and keeps every string as written', function (): void {
    $document = FlagDocument::fromJson('{
        "flags": {"f": {"state": "ENABLED", "variants": {"ref": {"$ref": "path"}, "none": {}}, "defaultVariant": "none",
                        "targeting": {"if": [{"$ref": "path"}, "ref", null]}}},
        "$evaluators": {"path": {"==": [{"var": "dir"}, "C:\\\\temp\\\\new\\\\d"]}}
    }');

    $match = (new DefaultFlagdEvaluator)->evaluate($document, 'f', FlagType::Object, [], 'u', ['dir' => 'C:\\temp\\new\\d']);

    expect(Json::canonical($match->value))->toBe('{"$ref":"path"}')
        ->and([$match->variant, $match->reason])->toBe(['ref', EvaluationReason::TargetingMatch]);
});

it('reports a flag over the expansion limits as a parse error while the rest of the document evaluates', function (): void {
    $evaluators = ['fan-0' => ['==' => [['var' => 'tier'], 'gold']]];
    for ($i = 1; $i <= 20; $i++) {
        $evaluators["fan-{$i}"] = ['or' => [['$ref' => 'fan-'.($i - 1)], ['$ref' => 'fan-'.($i - 1)]]];
    }
    $flag = static fn (string $ref): array => [
        'state' => 'ENABLED', 'variants' => ['on' => true, 'off' => false], 'defaultVariant' => 'off',
        'targeting' => ['if' => [['$ref' => $ref], 'on', 'off']],
    ];
    $document = FlagDocument::fromJsonValue(['flags' => ['huge' => $flag('fan-20'), 'small' => $flag('fan-5')], '$evaluators' => $evaluators]);
    $evaluator = new DefaultFlagdEvaluator;

    expect(featureFlagsOutcome($evaluator->evaluate($document, 'huge', FlagType::Boolean, true, 'u', ['tier' => 'gold'])))
        ->toBe([true, null, EvaluationReason::Error, EvaluationError::ParseError])
        ->and(featureFlagsOutcome($evaluator->evaluate($document, 'small', FlagType::Boolean, false, 'u', ['tier' => 'gold'])))
        ->toBe([true, 'on', EvaluationReason::TargetingMatch, null]);
});

it('expands references without touching the document it reads', function (): void {
    $document = FlagDocument::fromJson('{
        "flags": {"f": {"state": "ENABLED", "variants": {"on": true, "off": false}, "defaultVariant": "off",
                        "targeting": {"if": [{"$ref": "wrapped"}, "on", null]}}},
        "$evaluators": {"wrapped": {"!!": [{"0": {"$ref": "inner"}, "1": 2}]}, "inner": {"var": "x"}}
    }');
    $before = $document->toJson();

    $resolution = (new DefaultFlagdEvaluator)->evaluate($document, 'f', FlagType::Boolean, false, 'u', ['x' => 1]);

    expect([$resolution->value, $resolution->reason])->toBe([true, EvaluationReason::TargetingMatch])
        ->and($document->toJson())->toBe($before);
});

it('injects $flagd.timestamp from the clock and targetingKey over any attribute of that name', function (): void {
    $late = new DefaultFlagdEvaluator(static fn (): int => 1790000001);
    $early = new DefaultFlagdEvaluator(static fn (): int => 1790000000);

    expect($late->evaluate(featureFlagsEvaluatorDocument(), 'stamped', FlagType::String, '')->value)->toBe('late')
        ->and($early->evaluate(featureFlagsEvaluatorDocument(), 'stamped', FlagType::String, '')->value)->toBe('early')
        ->and($late->evaluate(featureFlagsEvaluatorDocument(), 'who', FlagType::String, '', 'u-1', ['targetingKey' => 'spoofed'])->value)->toBe('me')
        ->and($late->evaluate(featureFlagsEvaluatorDocument(), 'who', FlagType::String, '', null, ['targetingKey' => 'u-1'])->value)->toBe('other');
});

it('injects $flagd.flagKey over any attribute of that name', function (): void {
    $resolution = (new DefaultFlagdEvaluator)->evaluate(featureFlagsEvaluatorDocument(), 'self-named', FlagType::String, '', 'u', ['$flagd' => ['flagKey' => 'other']]);

    expect(featureFlagsOutcome($resolution))->toBe(['mine', 'self-named', EvaluationReason::TargetingMatch, null]);
});

it('merges flag metadata over document metadata on success and carries none on an error', function (): void {
    $evaluator = new DefaultFlagdEvaluator;

    expect($evaluator->evaluate(featureFlagsEvaluatorDocument(), 'ints', FlagType::Integer, 0)->metadata)->toBe(['team' => 'web', 'owner' => 'doc'])
        ->and($evaluator->evaluate(featureFlagsEvaluatorDocument(), 'missing', FlagType::Integer, 0)->metadata)->toBe([])
        ->and($evaluator->evaluate(featureFlagsEvaluatorDocument(), 'ints', FlagType::Boolean, false)->metadata)->toBe([]);
});

it('carries only the scalar document metadata, numeric-looking names included, under the flag metadata', function (): void {
    $document = FlagDocument::fromJson('{
        "flags": {"f": {"state": "DISABLED", "variants": {"on": true}, "defaultVariant": "on",
                        "metadata": {"owner": "flag", "2024": "flag year"}}},
        "metadata": {"owner": "doc", "list": [1], "object": {"a": 1}, "nothing": null, "1": 1.5, "2024": "doc year", "on": true}
    }');

    $metadata = (new DefaultFlagdEvaluator)->evaluate($document, 'f', FlagType::Boolean, false)->metadata;

    expect($metadata)->toBe(['owner' => 'flag', 1 => 1.5, 2024 => 'flag year', 'on' => true])
        ->and(array_map(static fn (int|string $name): string => (string) $name, array_keys($metadata)))->toBe(['owner', '1', '2024', 'on']);
});

it('ignores a field flagd does not define beside state: the flag still evaluates (RF6)', function (): void {
    $document = FlagDocument::fromJson('{"flags":{"described":{"state":"ENABLED","description":"outside metadata","rollout":{"owner":"x"},"variants":{"on":true,"off":false},"defaultVariant":"on"}}}');

    $resolution = (new DefaultFlagdEvaluator)->evaluate($document, 'described', FlagType::Boolean, false);

    expect([$resolution->value, $resolution->variant, $resolution->reason, $resolution->error])->toBe([true, 'on', EvaluationReason::Static, null]);
});

it('reads a defaultVariant "" as no default variant, even when a variant is named "" (R-default-empty)', function (): void {
    $evaluator = new DefaultFlagdEvaluator;
    $named = featureFlagsOneFlag(['state' => 'ENABLED', 'variants' => ['' => 'blank', 'x' => 'ex'], 'defaultVariant' => '']);
    $targeted = featureFlagsOneFlag(['state' => 'ENABLED', 'variants' => ['' => 'blank', 'x' => 'ex'], 'defaultVariant' => '', 'targeting' => ['if' => [false, 'x', null]]]);
    $unnamed = featureFlagsOneFlag(['state' => 'ENABLED', 'variants' => ['x' => 'ex'], 'defaultVariant' => '']);

    expect(featureFlagsOutcome($evaluator->evaluate($named, 'f', FlagType::String, 'fallback')))->toBe(['fallback', null, EvaluationReason::Default, null])
        ->and(featureFlagsOutcome($evaluator->evaluate($targeted, 'f', FlagType::String, 'fallback')))->toBe(['fallback', null, EvaluationReason::Default, null])
        ->and(featureFlagsOutcome($evaluator->evaluate($unnamed, 'f', FlagType::String, 'fallback')))->toBe(['fallback', null, EvaluationReason::Default, null]);
});

it('answers a targeting that is not an object as flagd does (m31)', function (mixed $targeting, array $expected): void {
    $document = featureFlagsOneFlag(['state' => 'ENABLED', 'variants' => ['on' => true, 'off' => false], 'defaultVariant' => 'on', 'targeting' => $targeting]);

    expect(featureFlagsOutcome((new DefaultFlagdEvaluator)->evaluate($document, 'f', FlagType::Boolean, false, 'u')))->toBe($expected);
})->with([
    'text' => ['on', [false, null, EvaluationReason::Error, EvaluationError::ParseError]],
    'true' => [true, [false, null, EvaluationReason::Error, EvaluationError::ParseError]],
    'a number' => [1, [false, null, EvaluationReason::Error, EvaluationError::ParseError]],
    'a list' => [[['var' => 'x']], [false, null, EvaluationReason::Error, EvaluationError::ParseError]],
    // Python reads a false-y targeting as none at all.
    'false' => [false, [true, 'on', EvaluationReason::Static, null]],
    'zero' => [0, [true, 'on', EvaluationReason::Static, null]],
    'empty text' => ['', [true, 'on', EvaluationReason::Static, null]],
]);

it('answers a dangling or null variant as flagd does', function (array $flag, FlagType $type, array $expected): void {
    expect(featureFlagsOutcome((new DefaultFlagdEvaluator)->evaluate(featureFlagsOneFlag($flag), 'f', $type, 'fallback', 'u')))->toBe($expected);
})->with([
    'a defaultVariant naming no variant' => [['state' => 'ENABLED', 'variants' => ['a' => 'A'], 'defaultVariant' => 'b'], FlagType::String, ['fallback', null, EvaluationReason::Error, EvaluationError::General]],
    'a default variant whose value is null' => [['state' => 'ENABLED', 'variants' => ['a' => null], 'defaultVariant' => 'a'], FlagType::String, ['fallback', null, EvaluationReason::Error, EvaluationError::TypeMismatch]],
    'targeting selecting a variant whose value is null' => [['state' => 'ENABLED', 'variants' => ['a' => 'A', 'n' => null], 'defaultVariant' => 'a', 'targeting' => ['if' => [true, 'n', null]]], FlagType::String, ['fallback', null, EvaluationReason::Error, EvaluationError::General]],
    'targeting selecting the empty name' => [['state' => 'ENABLED', 'variants' => ['a' => 'A', '' => 'blank'], 'defaultVariant' => 'a', 'targeting' => ['if' => [true, '', null]]], FlagType::String, ['fallback', null, EvaluationReason::Error, EvaluationError::General]],
    'targeting selecting an object' => [['state' => 'ENABLED', 'variants' => ['a' => 'A'], 'defaultVariant' => 'a', 'targeting' => ['if' => [true, ['a' => 1, 'b' => 2], null]]], FlagType::String, ['fallback', null, EvaluationReason::Error, EvaluationError::General]],
]);

it('maps an unknown operation to PARSE_ERROR and any other rule failure to GENERAL', function (): void {
    $evaluator = new DefaultFlagdEvaluator;
    $unknown = featureFlagsOneFlag(['state' => 'ENABLED', 'variants' => ['on' => true], 'defaultVariant' => 'on', 'targeting' => ['nope' => [1]]]);
    $failing = featureFlagsOneFlag(['state' => 'ENABLED', 'variants' => ['on' => true], 'defaultVariant' => 'on', 'targeting' => ['/' => [1, 0]]]);
    $dotted = featureFlagsOneFlag(['state' => 'ENABLED', 'variants' => ['on' => true], 'defaultVariant' => 'on', 'targeting' => ['in.x' => [1]]]);

    expect(featureFlagsOutcome($evaluator->evaluate($unknown, 'f', FlagType::Boolean, false)))->toBe([false, null, EvaluationReason::Error, EvaluationError::ParseError])
        ->and(featureFlagsOutcome($evaluator->evaluate($failing, 'f', FlagType::Boolean, false)))->toBe([false, null, EvaluationReason::Error, EvaluationError::General])
        ->and(featureFlagsOutcome($evaluator->evaluate($dotted, 'f', FlagType::Boolean, false)))->toBe([false, null, EvaluationReason::Error, EvaluationError::General]);
});

it('never throws, whatever the document', function (mixed $document): void {
    $resolution = (new DefaultFlagdEvaluator)->evaluate(FlagDocument::fromJsonValue($document), 'f', FlagType::String, 'fallback', 'u');

    expect($resolution->value)->toBe('fallback');
})->with([
    'flags not an object' => [['flags' => 'f']],
    'a flag that is not an object' => [['flags' => ['f' => true]]],
    'variants not an object' => [['flags' => ['f' => ['state' => 'ENABLED', 'variants' => [1, 2], 'defaultVariant' => '0']]]],
    'evaluators not an object' => [['flags' => ['f' => ['state' => 'ENABLED', 'variants' => ['a' => 1], 'targeting' => ['$ref' => 'x']]], '$evaluators' => 'x']],
    'metadata not an object' => [['flags' => ['f' => ['state' => 'ENABLED', 'variants' => ['a' => 1], 'metadata' => 'm']], 'metadata' => [1, 2]]],
    'a weight that is text' => [['flags' => ['f' => ['state' => 'ENABLED', 'variants' => ['a' => 1], 'targeting' => ['fractional' => [['a', 'x']]]]]]],
]);

/*
 | Context date-times (CONTRACT.md "Evaluation context"): the whole microseconds since the epoch over 1000,
 | correctly rounded. The rows are PyFly's (tests/feature_flags/test_context_datetimes.py) plus one past 2^53 µs,
 | where PHP's own int/int division would round twice (42994951600747.586).
 */
it('evaluates a context date-time as its exact epoch milliseconds', function (DateTimeInterface $value, float $milliseconds): void {
    $evaluator = new DefaultFlagdEvaluator;

    expect($evaluator->evaluate(featureFlagsEqualsFlag('t', $milliseconds), 'f', FlagType::Boolean, false, 'u', ['t' => $value])->value)->toBeTrue()
        ->and($evaluator->evaluate(featureFlagsEqualsFlag('t', featureFlagsNeighbour($milliseconds)), 'f', FlagType::Boolean, false, 'u', ['t' => $value])->value)->toBeFalse();
})->with([
    'aware-utc' => [new DateTimeImmutable('2026-01-15 12:34:56.789123', new DateTimeZone('UTC')), 1768480496789.123],
    'aware-plus-5-30' => [new DateTimeImmutable('2026-01-15 12:34:56.789123', new DateTimeZone('+05:30')), 1768460696789.123],
    'a mutable date-time in a named zone' => [new DateTime('2026-01-15 13:34:56.789123', new DateTimeZone('Europe/Madrid')), 1768480496789.123],
    'year-2066' => [new DateTimeImmutable('2066-02-17 18:09:13.204703', new DateTimeZone('UTC')), 3033655753204.703],
    'one-microsecond-before-the-epoch' => [new DateTimeImmutable('1969-12-31 23:59:59.999999', new DateTimeZone('UTC')), -0.001],
    '1960' => [new DateTimeImmutable('1960-05-17 06:07:08.123457', new DateTimeZone('UTC')), -303760371876.543],
    'midnight-1960' => [new DateTimeImmutable('1960-05-17', new DateTimeZone('UTC')), -303782400000.0],
    'midnight-2026' => [new DateTimeImmutable('2026-01-01', new DateTimeZone('UTC')), 1767225600000.0],
    'datetime-min' => [new DateTimeImmutable('0001-01-01 00:00:00', new DateTimeZone('UTC')), -62135596800000.0],
    'datetime-max (the microsecond rounds into the double)' => [new DateTimeImmutable('9999-12-31 23:59:59.999999', new DateTimeZone('UTC')), 253402300800000.0],
    'past 2^53 microseconds' => [new DateTimeImmutable('3332-06-15 18:06:40.747580', new DateTimeZone('UTC')), 42994951600747.58],
]);

it('does not round twice past 2^53 microseconds, as integer division would', function (): void {
    $naive = 42994951600747580 / 1000;
    $value = new DateTimeImmutable('3332-06-15 18:06:40.747580+00:00');

    expect($naive)->toBe(42994951600747.586)
        ->and((new DefaultFlagdEvaluator)->evaluate(featureFlagsEqualsFlag('t', $naive), 'f', FlagType::Boolean, false, 'u', ['t' => $value])->value)->toBeFalse();
});

it('writes a context date-time as the float Python writes for it', function (): void {
    $document = featureFlagsOneFlag(['state' => 'ENABLED', 'variants' => ['42994951600747.58' => 'exact', '-0.001' => 'before'], 'targeting' => ['var' => 't']]);
    $evaluator = new DefaultFlagdEvaluator;

    expect($evaluator->evaluate($document, 'f', FlagType::String, '', 'u', ['t' => new DateTimeImmutable('3332-06-15 18:06:40.747580+00:00')])->variant)->toBe('42994951600747.58')
        ->and($evaluator->evaluate($document, 'f', FlagType::String, '', 'u', ['t' => new DateTimeImmutable('1969-12-31 23:59:59.999999+00:00')])->variant)->toBe('-0.001');
});

it('converts date-times nested in lists and objects', function (string $path, array $attributes): void {
    $document = featureFlagsOneFlag([
        'state' => 'ENABLED', 'variants' => ['on' => true, 'off' => false], 'defaultVariant' => 'off',
        'targeting' => ['if' => [['>' => [['var' => $path], 1735689600000]], 'on', null]],
    ]);

    expect((new DefaultFlagdEvaluator)->evaluate($document, 'f', FlagType::Boolean, false, 'u', $attributes)->reason)->toBe(EvaluationReason::TargetingMatch);
})->with([
    'an object' => ['user.joined', ['user' => ['joined' => new DateTimeImmutable('2026-01-15 00:00:00+00:00')]]],
    'a list' => ['events.1', ['events' => [new DateTimeImmutable('2024-01-01 00:00:00+00:00'), new DateTimeImmutable('2026-01-15 00:00:00+00:00')]]],
    'a stdClass in a list in an object' => ['a.b.0.c', ['a' => ['b' => [(object) ['c' => new DateTimeImmutable('2026-01-15 00:00:00+00:00')]]]]],
]);

it('leaves the caller context as it was', function (): void {
    $joined = new DateTimeImmutable('2026-01-15 00:00:00+00:00');
    $holder = (object) ['joined' => $joined, 'n' => 1];
    $attributes = ['user' => $holder, 'tags' => ['a', $joined]];
    $document = featureFlagsOneFlag([
        'state' => 'ENABLED', 'variants' => ['on' => true, 'off' => false], 'defaultVariant' => 'off',
        'targeting' => ['if' => [['>' => [['var' => 'user.joined'], 1735689600000]], 'on', null]],
    ]);

    $resolution = (new DefaultFlagdEvaluator)->evaluate($document, 'f', FlagType::Boolean, false, 'u', $attributes);

    expect($resolution->value)->toBeTrue()
        ->and($attributes['user'])->toBe($holder)
        ->and($holder->joined)->toBe($joined)
        ->and($attributes['tags'][1])->toBe($joined)
        ->and($joined->format('Y-m-d'))->toBe('2026-01-15');
});

it('converts a date-time inside an object that contains itself', function (): void {
    $cycle = new stdClass;
    $cycle->when = new DateTimeImmutable('2026-01-15 00:00:00+00:00');
    $cycle->self = $cycle;
    $document = featureFlagsOneFlag([
        'state' => 'ENABLED', 'variants' => ['on' => true, 'off' => false], 'defaultVariant' => 'off',
        'targeting' => ['if' => [['>' => [['var' => 'cycle.self.self.when'], 1735689600000]], 'on', null]],
    ]);

    $resolution = (new DefaultFlagdEvaluator)->evaluate($document, 'f', FlagType::Boolean, false, 'u', ['cycle' => $cycle]);

    expect([$resolution->value, $resolution->reason])->toBe([true, EvaluationReason::TargetingMatch])
        ->and($cycle->self)->toBe($cycle)
        ->and($cycle->when)->toBeInstanceOf(DateTimeImmutable::class);
});

/*
 | The date-time walk is bounded as PyFly's is (R-L-T5-bounds; pyfly/feature_flags/provider.py
 | _epoch_millis_context): containers deeper than 128 levels (the attributes are level 1) are not entered, and after
 | 10 000 values the rest is left as it is. Every boundary below is what PyFly's own walk does with the same context.
 */

/**
 * Attributes whose date-time sits in the container at nesting $level (the attributes are level 1).
 *
 * @return array<array-key, mixed>
 */
function featureFlagsDateTimeAtLevel(int $level): array
{
    $attributes = ['when' => new DateTimeImmutable('2026-01-15 00:00:00+00:00')];
    for ($i = 1; $i < $level; $i++) {
        $attributes = ['in' => $attributes];
    }

    return $attributes;
}

/**
 * "on" when the context value at $path is a number after 2025-01-01T00:00:00Z in epoch milliseconds: a converted
 * date-time; one left as it is reads as no number at all.
 */
function featureFlagsAfter2025(string $path): FlagDocument
{
    return featureFlagsOneFlag([
        'state' => 'ENABLED', 'variants' => ['on' => true, 'off' => false], 'defaultVariant' => 'off',
        'targeting' => ['if' => [['>' => [['var' => $path], 1735689600000]], 'on', null]],
    ]);
}

it('enters containers down to 128 levels and no further', function (int $level, EvaluationReason $reason): void {
    $path = str_repeat('in.', $level - 1).'when';

    expect((new DefaultFlagdEvaluator)->evaluate(featureFlagsAfter2025($path), 'f', FlagType::Boolean, false, 'u', featureFlagsDateTimeAtLevel($level))->reason)->toBe($reason);
})->with([
    'level 127' => [127, EvaluationReason::TargetingMatch],
    'level 128' => [128, EvaluationReason::TargetingMatch],
    'level 129: not entered, the date-time stays one' => [129, EvaluationReason::Default],
]);

it('converts the first 10 000 values it reads and leaves the rest', function (int $filler, EvaluationReason $reason): void {
    // The attributes, the list and its items come first: the date-time is value $filler + 3.
    $attributes = ['filler' => array_fill(0, $filler, 0), 'when' => new DateTimeImmutable('2026-01-15 00:00:00+00:00')];

    expect((new DefaultFlagdEvaluator)->evaluate(featureFlagsAfter2025('when'), 'f', FlagType::Boolean, false, 'u', $attributes)->reason)->toBe($reason);
})->with([
    'value 10 000' => [9_997, EvaluationReason::TargetingMatch],
    'value 10 001' => [9_998, EvaluationReason::Default],
]);

it('walks a ladder of shared arrays within the budget, whatever its paths number', function (int $rungs): void {
    $ladder = ['when' => new DateTimeImmutable('2026-01-15 00:00:00+00:00')];
    for ($i = 0; $i < $rungs; $i++) {
        $ladder = ['l' => $ladder, 'r' => $ladder];
    }
    $evaluator = new DefaultFlagdEvaluator;

    $started = hrtime(true);
    $first = $evaluator->evaluate(featureFlagsAfter2025('ladder.'.str_repeat('l.', $rungs).'when'), 'f', FlagType::Boolean, false, 'u', ['ladder' => $ladder]);
    $last = $evaluator->evaluate(featureFlagsAfter2025('ladder.'.str_repeat('r.', $rungs).'when'), 'f', FlagType::Boolean, false, 'u', ['ladder' => $ladder]);
    $seconds = (hrtime(true) - $started) / 1e9;

    expect([$first->reason, $last->reason])->toBe([EvaluationReason::TargetingMatch, EvaluationReason::Default])
        ->and($seconds)->toBeLessThan(1.0);
})->with([
    '2^16 paths' => [16],
    '2^60 paths' => [60],
]);

/*
 | A context value that contains itself (R-L-T5-cycles). Python compares a container with itself by identity, so the
 | same object is equal to itself at once; two distinct ones recurse until RecursionError, which the OpenFeature SDK
 | reports as GENERAL — here a JsonLogicError at JsonLogic::MAX_DATA_DEPTH, never a PHP fatal. Every row is what the
 | reference evaluator answers for the same data.
 */
it('answers a rule over a context value that contains itself as the reference does', function (array $targeting, EvaluationReason $reason, ?EvaluationError $error): void {
    $a = new stdClass;
    $a->self = $a;
    $a->n = 1;
    $b = new stdClass;
    $b->self = $b;
    $b->n = 1;
    $list = [1];
    $list[] = &$list;
    $document = featureFlagsOneFlag(['state' => 'ENABLED', 'variants' => ['on' => true, 'off' => false], 'defaultVariant' => 'off', 'targeting' => ['if' => [$targeting, 'on', null]]]);

    $resolution = (new DefaultFlagdEvaluator)->evaluate($document, 'f', FlagType::Boolean, false, 'u', ['a' => $a, 'b' => $b, 'hay' => [$a], 'list' => $list]);

    expect([$resolution->reason, $resolution->error])->toBe([$reason, $error])
        ->and($resolution->value)->toBe($reason === EvaluationReason::TargetingMatch);
})->with([
    '=== of one object with itself' => [['===' => [['var' => 'a'], ['var' => 'a']]], EvaluationReason::TargetingMatch, null],
    '=== of two distinct ones' => [['===' => [['var' => 'a'], ['var' => 'b']]], EvaluationReason::Error, EvaluationError::General],
    '!== of two distinct ones' => [['!==' => [['var' => 'a'], ['var' => 'b']]], EvaluationReason::Error, EvaluationError::General],
    '== of two distinct objects compares identity' => [['==' => [['var' => 'a'], ['var' => 'b']]], EvaluationReason::Default, null],
    'in a list holding the same object' => [['in' => [['var' => 'a'], ['var' => 'hay']]], EvaluationReason::TargetingMatch, null],
    'in a list holding a distinct one' => [['in' => [['var' => 'b'], ['var' => 'hay']]], EvaluationReason::Error, EvaluationError::General],
    'cat of a list that contains itself' => [['cat' => [['var' => 'list']]], EvaluationReason::Error, EvaluationError::General],
    'in text with such a list' => [['in' => [['var' => 'list'], 'abc']], EvaluationReason::Error, EvaluationError::General],
    'cat of the object' => [['cat' => [['var' => 'a']]], EvaluationReason::TargetingMatch, null],
    'a path through the object' => [['==' => [['var' => 'a.self.self.n'], 1]], EvaluationReason::TargetingMatch, null],
]);

it('names a variant after a context object that contains itself as Python repr() does', function (): void {
    $a = new stdClass;
    $a->self = $a;
    $a->n = 1;
    $document = featureFlagsOneFlag(['state' => 'ENABLED', 'variants' => ["{'self': {...}, 'n': 1}" => 'cyclic', 'x' => 'x'], 'defaultVariant' => 'x', 'targeting' => ['var' => 'a']]);

    $resolution = (new DefaultFlagdEvaluator)->evaluate($document, 'f', FlagType::String, '', 'u', ['a' => $a]);

    expect(featureFlagsOutcome($resolution))->toBe(['cyclic', "{'self': {...}, 'n': 1}", EvaluationReason::TargetingMatch, null]);
});
