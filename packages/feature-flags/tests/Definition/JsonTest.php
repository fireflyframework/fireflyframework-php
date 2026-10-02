<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Definition\Json;

it('keeps an empty object and a list-like object as stdClass and every other object as an array', function (): void {
    /** @var array{a: mixed, b: mixed, c: mixed, d: mixed, e: list<array{z: mixed}>} $decoded */
    $decoded = Json::decode('{"a": {}, "b": [], "c": {"0": "x"}, "d": {"k": 1}, "e": [{"z": {}}]}');

    expect($decoded)->toBeArray()
        ->and($decoded['a'])->toBeInstanceOf(stdClass::class)
        ->and($decoded['b'])->toBe([])
        ->and($decoded['c'])->toBeInstanceOf(stdClass::class)
        ->and($decoded['d'])->toBe(['k' => 1])
        ->and($decoded['e'][0]['z'])->toBeInstanceOf(stdClass::class);
});

it('round-trips {} and [] and keeps a float zero a float', function (): void {
    $json = '{"empty":{},"list":[],"zero":0.0,"one":1,"text":"día"}';

    expect(Json::encode(Json::decode($json)))->toBe($json);
});

it('canonicalises member order but never confuses {} with []', function (): void {
    expect(Json::canonical(Json::decode('{"b":1,"a":{"d":[],"c":{}}}')))->toBe('{"a":{"c":{},"d":[]},"b":1}')
        ->and(Json::canonical(new stdClass))->not->toBe(Json::canonical([]));
});

it('classifies objects and lists', function (): void {
    expect(Json::isObject(new stdClass))->toBeTrue()
        ->and(Json::isObject(['a' => 1]))->toBeTrue()
        ->and(Json::isObject([]))->toBeFalse()
        ->and(Json::isObject([1, 2]))->toBeFalse()
        ->and(Json::isList(Json::decode('[]')))->toBeTrue()
        ->and(Json::isList(Json::decode('[1, 2]')))->toBeTrue()
        ->and(Json::isList(Json::decode('{"a": 1}')))->toBeFalse()
        ->and(Json::members((object) ['x' => 1]))->toBe(['x' => 1])
        ->and(Json::members('scalar'))->toBe([]);
});

it('converts to plain PHP arrays for OpenFeature and forces object positions', function (): void {
    expect(Json::toPhp(Json::decode('{"a":{},"b":[{"c":{}}]}')))->toBe(['a' => [], 'b' => [['c' => []]]])
        ->and(Json::object([]))->toBeInstanceOf(stdClass::class)
        ->and(Json::object(['0' => 'v']))->toBeInstanceOf(stdClass::class)
        ->and(Json::object(['on' => true]))->toBe(['on' => true]);
});
