<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Definition\FlagDefinition;
use Firefly\FeatureFlags\Definition\FlagDocument;
use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Evaluation\FlagType;

/**
 * The names of a map's members as text: the `(string)` cast every consumer of an array-key map needs.
 *
 * @param  array<array-key, mixed>  $members
 * @return list<string>
 */
function featureFlagsNames(array $members): array
{
    return array_map(static fn (int|string $key): string => (string) $key, array_keys($members));
}

it('reads a flagd document and answers every accessor', function (): void {
    $document = FlagDocument::fromJson((string) json_encode([
        'flags' => [
            'new-checkout' => [
                'state' => 'ENABLED',
                'variants' => ['on' => true, 'off' => false],
                'defaultVariant' => 'off',
                'targeting' => ['if' => [['in' => ['beta', ['var' => 'roles']]], 'on', null]],
                'metadata' => ['owner' => 'payments', 'expires' => '2025-01-01'],
            ],
        ],
        '$evaluators' => ['is-beta' => ['in' => ['beta', ['var' => 'roles']]]],
        'metadata' => ['team' => 'web'],
    ]));

    $flag = $document->flag('new-checkout');

    expect($flag)->not->toBeNull()
        ->and($flag?->state())->toBe('ENABLED')
        ->and($flag?->isDisabled())->toBeFalse()
        ->and($flag?->variantNames())->toBe(['on', 'off'])
        ->and($flag?->defaultVariant())->toBe('off')
        ->and($flag?->targeting())->not->toBeNull()
        ->and($flag?->metadata())->toBe(['owner' => 'payments', 'expires' => '2025-01-01'])
        ->and($flag?->valueType())->toBe('boolean')
        ->and($flag?->flagType())->toBe(FlagType::Boolean)
        ->and($flag?->isExpired('2026-10-01'))->toBeTrue()
        ->and($flag?->isExpired('2025-01-01'))->toBeFalse()
        ->and($document->evaluators)->toHaveKey('is-beta')
        ->and($document->metadata)->toBe(['team' => 'web']);
});

it('treats a missing or empty targeting as no targeting and a missing or empty default variant as none', function (): void {
    $document = FlagDocument::fromJson('{"flags":{"a":{"state":"ENABLED","variants":{"true":1,"false":0},"targeting":{}},"b":{"state":"ENABLED","variants":{"x":1.5},"defaultVariant":""}}}');

    expect($document->flag('a')?->targeting())->toBeNull()
        ->and($document->flag('b')?->targeting())->toBeNull()
        ->and($document->flag('a')?->defaultVariant())->toBeNull()
        ->and($document->flag('a')?->flagType())->toBe(FlagType::Integer)
        ->and($document->flag('b')?->defaultVariant())->toBeNull()
        ->and($document->flag('b')?->flagType())->toBe(FlagType::Float);
});

it('keeps numeric-looking flag keys and variant names as strings', function (): void {
    $document = FlagDocument::fromJson('{"flags":{"2024":{"state":"ENABLED","variants":{"0":"zero","1":"one"},"defaultVariant":"1"}}}');

    expect($document->keys())->toBe(['2024'])
        ->and($document->flag('2024')?->key)->toBe('2024')
        ->and($document->flag('2024')?->variantNames())->toBe(['0', '1'])
        ->and($document->flag('2024')?->variantValue('1'))->toBe('one');
});

it('keys numeric-looking metadata names and fingerprinted flag keys as ints that a (string) cast restores', function (): void {
    $document = FlagDocument::fromJson('{"flags":{"2024":{"state":"ENABLED","variants":{"on":true},"defaultVariant":"on","metadata":{"1":"one","2024":"year","owner":"web"}},"1":{"state":"ENABLED","variants":{"on":true},"defaultVariant":"on"}},"metadata":{"1":"first","2024":"launch","team":"web"}}');
    $flagMetadata = $document->flag('2024')?->metadata() ?? [];
    $fingerprints = $document->keyFingerprints();

    // PHP cannot hold "1" or "2024" as a string key: the three maps are array-key, so a consumer must cast.
    $intKeys = 0;
    foreach ([...array_keys($flagMetadata), ...array_keys($fingerprints), ...array_keys($document->metadata)] as $key) {
        $intKeys += is_int($key) ? 1 : 0;
    }

    expect($intKeys)->toBe(6)
        ->and($flagMetadata['1'] ?? null)->toBe('one')
        ->and($flagMetadata['2024'] ?? null)->toBe('year')
        ->and(featureFlagsNames($flagMetadata))->toBe(['1', '2024', 'owner'])
        ->and($fingerprints['1'] ?? null)->toMatch('/^[0-9a-f]{64}$/')
        ->and($fingerprints['2024'] ?? null)->toMatch('/^[0-9a-f]{64}$/')
        ->and(featureFlagsNames($fingerprints))->toBe(['1', '2024'])
        ->and($document->metadata['1'] ?? null)->toBe('first')
        ->and($document->metadata['2024'] ?? null)->toBe('launch')
        ->and(featureFlagsNames($document->metadata))->toBe(['1', '2024', 'team']);
});

it('keeps non-scalar document metadata values as decoded, so validation can reject them', function (): void {
    $document = FlagDocument::fromJson('{"metadata":{"n":null,"o":{"a":1},"e":{},"l":[1],"s":"x"}}');

    expect(array_keys($document->metadata))->toBe(['n', 'o', 'e', 'l', 's'])
        ->and($document->metadata['n'])->toBeNull()
        ->and($document->metadata['o'])->toBe(['a' => 1])
        ->and($document->metadata['e'])->toBeInstanceOf(stdClass::class)
        ->and($document->metadata['l'])->toBe([1])
        ->and($document->metadata['s'])->toBe('x')
        ->and($document->toJson())->toBe('{"flags":{},"$evaluators":{},"metadata":{"n":null,"o":{"a":1},"e":{},"l":[1],"s":"x"}}');
});

it('encodes PHP empty arrays in the three object positions of a definition as {}', function (): void {
    $flag = new FlagDefinition('k', ['variants' => [], 'metadata' => [], 'targeting' => []]);

    expect(Json::encode($flag->toJsonValue()))->toBe('{"variants":{},"metadata":{},"targeting":{}}')
        ->and((new FlagDocument(['k' => $flag]))->toJson())->toBe('{"flags":{"k":{"variants":{},"metadata":{},"targeting":{}}},"$evaluators":{},"metadata":{}}');
});

it('moves the document fingerprint and that flag fingerprint, and only that one, when one value changes', function (): void {
    $json = '{"flags":{"x":{"state":"ENABLED","variants":{"on":true,"off":false},"defaultVariant":"off","metadata":{"owner":"web"}},"y":{"state":"ENABLED","variants":{"v":"a"},"defaultVariant":"v"}}}';
    $original = FlagDocument::fromJson($json);
    $changes = [
        'a variant value' => FlagDocument::fromJson(str_replace('"off":false', '"off":true', $json)),
        'a metadata value' => FlagDocument::fromJson(str_replace('"owner":"web"', '"owner":"payments"', $json)),
    ];

    foreach ($changes as $change => $changed) {
        expect($changed->fingerprint())->not->toBe($original->fingerprint(), $change)
            ->and($changed->keyFingerprints()['x'] ?? null)->not->toBe($original->keyFingerprints()['x'] ?? null, $change)
            ->and($changed->keyFingerprints()['y'] ?? null)->toBe($original->keyFingerprints()['y'] ?? null, $change);
    }
});

it('serialises empty maps as objects and fingerprints independently of member order', function (): void {
    $empty = new FlagDocument;
    $a = FlagDocument::fromJson('{"flags":{"x":{"state":"ENABLED","variants":{"e":{},"f":{"k":1}},"defaultVariant":"e"}}}');
    $b = FlagDocument::fromJson('{"flags":{"x":{"defaultVariant":"e","variants":{"f":{"k":1},"e":{}},"state":"ENABLED"}}}');

    expect($empty->toJson())->toBe('{"flags":{},"$evaluators":{},"metadata":{}}')
        ->and($a->toJson())->toContain('"e":{}')
        ->and($a->fingerprint())->toBe($b->fingerprint())
        ->and($a->keyFingerprints())->toBe($b->keyFingerprints());
});
