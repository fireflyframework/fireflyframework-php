<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Definition\FlagDocument;
use Firefly\FeatureFlags\Evaluation\FlagType;

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

it('treats a missing or empty targeting as no targeting and an empty default variant as none', function (): void {
    $document = FlagDocument::fromJson('{"flags":{"a":{"state":"ENABLED","variants":{"true":1,"false":0},"defaultVariant":true,"targeting":{}},"b":{"state":"ENABLED","variants":{"x":1.5},"defaultVariant":""}}}');

    expect($document->flag('a')?->targeting())->toBeNull()
        ->and($document->flag('b')?->targeting())->toBeNull()
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

it('serialises empty maps as objects and fingerprints independently of member order', function (): void {
    $empty = new FlagDocument;
    $a = FlagDocument::fromJson('{"flags":{"x":{"state":"ENABLED","variants":{"e":{},"f":{"k":1}},"defaultVariant":"e"}}}');
    $b = FlagDocument::fromJson('{"flags":{"x":{"defaultVariant":"e","variants":{"f":{"k":1},"e":{}},"state":"ENABLED"}}}');

    expect($empty->toJson())->toBe('{"flags":{},"$evaluators":{},"metadata":{}}')
        ->and($a->toJson())->toContain('"e":{}')
        ->and($a->fingerprint())->toBe($b->fingerprint())
        ->and($a->keyFingerprints())->toBe($b->keyFingerprints());
});
