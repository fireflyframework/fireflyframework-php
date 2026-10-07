<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Evaluation\FlagdOperators;
use Firefly\FeatureFlags\Evaluation\Fractional;
use Firefly\FeatureFlags\Evaluation\JsonLogicError;
use Firefly\FeatureFlags\Evaluation\SemVer;
use Firefly\FeatureFlags\Evaluation\StringOps;

/*
 | Every expected value is what the reference evaluator's operators (openfeature-flagd-core 1.0.0,
 | targeting/custom_ops.py, on mmh3 5.3.1 and semver 3.1.0) return for the same arguments: a row that fails
 | here is a place where a PHP and a Python service would put the same user in different variants.
 */
it('compares versions as flagd does', function (array $args, ?bool $expected): void {
    /** @var list<mixed> $args */
    expect(SemVer::evaluate([], $args))->toBe($expected);
})->with([
    'equal' => [['1.2.3', '=', '1.2.3'], true],
    'prerelease below release' => [['1.2.3-alpha', '<', '1.2.3'], true],
    'numeric identifier below alphanumeric' => [['1.0.0-alpha.1', '<', '1.0.0-alpha.beta'], true],
    'numeric identifiers compare as numbers' => [['1.0.0-beta.11', '>', '1.0.0-beta.2'], true],
    'shorter prerelease first' => [['1.0.0-alpha', '<', '1.0.0-alpha.1'], true],
    'v prefix dropped' => [['v2.4.0', '>=', '2.4.0'], true],
    'caret is same major' => [['2', '^', '2.9.1'], true],
    'tilde is same major and minor' => [['2.1', '~', '2.1.7'], true],
    'tilde refuses another minor' => [['2.2', '~', '2.1.7'], false],
    'build metadata ignored' => [['1.0.0+build.1', '=', '1.0.0+build.2'], true],
    'leading zero is not semver' => [['01.0.0', '=', '1.0.0'], null],
    'integer padded' => [['1.0', '=', 1], true],
    'float stringified' => [[1.5, '<', '1.10'], true],
    'unknown operator' => [['1.0.0', '===', '1.0.0'], null],
    'four parts do not parse' => [['2.0.0.0', '=', '2.0.0'], null],
    'not equal' => [['1.0.0', '!=', '1.0.1'], true],
    'less or equal' => [['1.0.0', '<=', '1.0.0'], true],
    'boolean is not a version' => [[true, '=', '1.0.0'], null],
    'null is not a version' => [[null, '=', '1.0.0'], null],
    'two arguments' => [['1.0.0', '='], null],
]);

/*
 | The reference reads a number as str() of it, so a float is Python's repr — not PHP's string cast, which
 | rounds to 14 digits (0.1 + 0.2 → "0.3") and writes 1e15 as "1.0E+15" — and an exponent never parses.
 */
it('reads a number as the version Python writes it', function (array $args, ?bool $expected): void {
    /** @var list<mixed> $args */
    expect(SemVer::evaluate([], $args))->toBe($expected);
})->with([
    'all seventeen digits' => [[0.1 + 0.2, '=', '0.30000000000000004'], true],
    'not rounded to fourteen' => [[0.1 + 0.2, '=', '0.3'], false],
    'whole float gains .0' => [[1.0, '=', '1'], true],
    'zero' => [[0.0, '=', '0.0.0'], true],
    'zero integer' => [[0, '=', '0.0.0'], true],
    'fifteen digits stay fixed' => [[123456789012345.0, '=', '123456789012345'], true],
    '1e15 stays fixed' => [[1e15, '=', '1000000000000000'], true],
    'sixteen significant digits' => [[999999999999999.9, '=', '999999999999999.9'], true],
    '1e16 is an exponent' => [[1e16, '=', '10000000000000000'], null],
    'small is an exponent' => [[1e-5, '=', '1'], null],
    'smallest fixed has a leading zero minor' => [[0.0001, '=', '0.0001'], null],
    'negative' => [[-1.5, '=', '1.5'], null],
    'negative zero' => [[-0.0, '=', '0'], null],
    'negative integer' => [[-1, '<', '0'], null],
    'infinity' => [[INF, '=', '1'], null],
    'not a number' => [[NAN, '=', '1'], null],
]);

it('parses versions exactly as python-semver does', function (array $args, ?bool $expected): void {
    /** @var list<mixed> $args */
    expect(SemVer::evaluate([], $args))->toBe($expected);
})->with([
    'majors beyond 64 bits' => [['100000000000000000000000000001.0.0', '>', '100000000000000000000000000000.0.0'], true],
    'just beyond 64 bits' => [['18446744073709551616.0.0', '>', '18446744073709551615.0.0'], true],
    'hyphens inside a prerelease' => [['1.0.0-a-b', '<', '1.0.0-a-c'], true],
    'hyphen right after the patch' => [['1.2.3-4-5', '=', '1.2.3-4-5'], true],
    'hyphen in build metadata' => [['1.0.0+b-1.2', '=', '1.0.0'], true],
    'prerelease and build' => [['1.0.0-beta+exp.sha.5114f85', '=', '1.0.0-beta'], true],
    'empty prerelease' => [['1.0.0-', '=', '1.0.0'], null],
    'empty build' => [['1.0.0+', '=', '1.0.0'], null],
    'leading zero numeric prerelease' => [['1.0.0-01', '=', '1.0.0'], null],
    'leading zeros before a letter' => [['1.0.0-00a', '=', '1.0.0-00a'], true],
    'empty prerelease identifier' => [['1.0.0-a..b', '=', '1.0.0'], null],
    'second plus' => [['1.2.3+4+5', '=', '1.2.3'], null],
    'trailing newline' => [["1.0.0\n", '=', '1.0.0'], null],
    'leading space' => [[' 1.0.0', '=', '1.0.0'], null],
    'empty minor' => [['1..0', '=', '1.0.0'], null],
    'v alone' => [['v', '=', '1.0.0'], null],
    'one v only' => [['vv1.0.0', '=', '1.0.0'], null],
    'capital V' => [['V1', '=', '1.0.0'], true],
    'major with a prerelease padded' => [['1-alpha', '=', '1.0.0-alpha'], true],
    'major with build padded' => [['1+build', '=', '1.0.0'], true],
    'major and minor with a prerelease' => [['1.2-rc.1', '<', '1.2.0'], true],
    'major and minor with build' => [['1.2+3', '=', '1.2.0'], true],
    'padding before a dotted prerelease' => [['1.2-3.4', '=', '1.2.0-3.4'], true],
    'hyphen alone after the major' => [['1-', '=', '1.0.0'], null],
    'fullwidth digit' => [['１.0.0', '=', '1.0.0'], null],
    'arabic-indic digit in a prerelease' => [['1.0.0-١', '=', '1.0.0'], null],
    'numeric before alphanumeric with a digit' => [['1.0.0-0a', '>', '1.0.0-0'], true],
    'letter above number' => [['1.0.0-a', '>', '1.0.0-1'], true],
    'ASCII order' => [['1.0.0-A', '<', '1.0.0-a'], true],
    'longer text above its prefix' => [['1.0.0-alpha', '>', '1.0.0-alph'], true],
    'more identifiers above fewer' => [['1.0.0-a.b.c.d', '>', '1.0.0-a.b.c'], true],
    'caret ignores a prerelease' => [['1.0.0-rc.1', '^', '1.5.0'], true],
    'tilde ignores a prerelease' => [['1.2.3-rc.1', '~', '1.2.0'], true],
    'operator not text' => [['1.0.0', 1, '1.0.0'], null],
    'a list' => [[[1, 2], '=', '1'], null],
    'four arguments' => [['1.0.0', '=', '1.0.0', 'x'], null],
    'no arguments' => [[], null],
]);

it('reads a long version as Python does, with no backtracking limit to hit', function (): void {
    // python-semver's own pattern under PCRE gives up (preg_match() === false, JIT stack exhausted) on the first.
    $identifiers = str_repeat('a.', 100_000).'a';
    $digits = str_repeat('9', 200_000);

    expect(SemVer::evaluate([], ["1.0.0-{$identifiers}", '<', '1.0.0']))->toBeTrue()
        ->and(SemVer::evaluate([], ["1.0.0-{$digits}a", '<', '1.0.0']))->toBeTrue()
        ->and(SemVer::evaluate([], ["1.0.0-{$digits}!", '<', '1.0.0']))->toBeNull();
});

it('stops where Python stops converting digits to an int (4300 digits)', function (): void {
    $fits = str_repeat('9', 4300);
    $over = str_repeat('9', 4301);

    // A major, minor or patch is int() at parse time: the ValueError makes sem_ver null.
    expect(SemVer::evaluate([], ["{$fits}.0.0", '>', '1.0.0']))->toBeTrue()
        ->and(SemVer::evaluate([], ["{$over}.0.0", '>', '1.0.0']))->toBeNull()
        ->and(SemVer::evaluate([], ["1.{$over}.0", '>', '1.0.0']))->toBeNull()
        ->and(SemVer::evaluate([], ["1.0.{$over}", '>', '1.0.0']))->toBeNull()
        // A numeric prerelease identifier is int() only when two prereleases are compared, outside the reference's
        // try: the ValueError fails the rule. ^ and ~ never compare prereleases, nor do different cores.
        ->and(SemVer::evaluate([], ["1.0.0-{$fits}", '>', '1.0.0-1']))->toBeTrue()
        ->and(SemVer::evaluate([], ["1.0.0-{$over}", '^', '1.0.0']))->toBeTrue()
        ->and(SemVer::evaluate([], ["1.0.0-{$over}", '~', '1.0.0']))->toBeTrue()
        ->and(SemVer::evaluate([], ["1.0.0-{$over}", '=', '1.0.1']))->toBeFalse()
        ->and(SemVer::evaluate([], ["1.0.0-{$over}a", '<', '1.0.0']))->toBeTrue()
        ->and(SemVer::evaluate([], ["1.0.0+{$over}", '=', '1.0.0']))->toBeTrue()
        ->and(fn (): ?bool => SemVer::evaluate([], ["1.0.0-{$over}", '=', '1.0.0']))->toThrow(JsonLogicError::class)
        ->and(fn (): ?bool => SemVer::evaluate([], ['1.0.0', '=', "1.0.0-{$over}"]))->toThrow(JsonLogicError::class)
        ->and(fn (): ?bool => SemVer::evaluate([], ["1.0.0-x.{$over}", '<', '1.0.0-y']))->toThrow(JsonLogicError::class);
});

it('matches prefixes and suffixes of two strings only', function (array $args, ?bool $starts, ?bool $ends): void {
    /** @var list<mixed> $args */
    expect(StringOps::startsWith([], $args))->toBe($starts)
        ->and(StringOps::endsWith([], $args))->toBe($ends);
})->with([
    [['abcdef', 'abc'], true, false],
    [['abcdef', 'xyz'], false, false],
    [[123, '1'], null, null],
    [['abc'], null, null],
    [['abc', 'a', 'b'], null, null],
    [['', ''], true, true],
    [['abc', ''], true, true],
    [['José', 'Jo'], true, false],
    [['José', 'é'], false, true],
    [['abc', 1], null, null],
    [['abc', null], null, null],
    [[['a'], 'a'], null, null],
    [[], null, null],
]);

it('buckets like fractional-v2', function (array $args, mixed $expected): void {
    /** @var list<mixed> $args */
    $data = ['$flagd' => ['flagKey' => 'exp'], 'targetingKey' => 'user-42'];

    expect(Fractional::evaluate($data, $args))->toBe($expected);
})->with([
    'flag key + targeting key' => [[['a', 50], ['b', 50]], 'b'],
    'explicit bucket key' => [['custom-seed', ['a', 50], ['b', 50]], 'a'],
    'weight defaults to 1' => [[['a'], ['b', 1]], 'b'],
    'all-zero weights' => [[['a', 0], ['b', 0]], null],
    'negative weight clamps to 0' => [[['a', -5], ['b', 10]], 'b'],
    'float weight refused' => [[['a', 1.5], ['b', 1]], null],
    'boolean weight refused' => [[['a', true], ['b', 1]], null],
    'empty bucket refused' => [[[]], null],
    'empty bucket key' => [['', ['a', 1]], null],
    'null bucket key falls back and then refuses itself' => [[null, ['a', 1]], null],
    'total over 2^31-1' => [[['a', 2147483647], ['b', 1]], null],
    'single entry' => [[['only']], 'only'],
    'total of exactly 2^31-1' => [[['a', 2147483647]], 'a'],
    'weights summing past PHP_INT_MAX' => [[['a', PHP_INT_MAX], ['b', PHP_INT_MAX]], null],
    'bucket key without buckets' => [['key'], null],
    'no arguments' => [[], null],
    'three-item bucket refused' => [[['a', 1, 2]], null],
    'object bucket refused' => [[['a' => 1]], null],
    'number before the buckets refused' => [[5, ['a', 1]], null],
    'variant of any type' => [[[true, 1]], true],
    'null variant' => [[[null, 1]], null],
    'list variant' => [[[[1, 2], 1]], [1, 2]],
]);

it('computes the bucket exactly at the hash and weight extremes', function (array $args, string $expected): void {
    /** @var list<mixed> $args */
    // (hash * total) >> 32 stays an int: hash < 2^32 and total <= 2^31 - 1, so the product is below 2^63.
    expect(Fractional::evaluate([], $args))->toBe($expected);
})->with([
    'highest hash, largest total' => [['ceQdGm', ['a', 2147483646], ['b', 1]], 'b'],
    'highest hash, one bucket' => [['ceQdGm', ['a', 2147483647]], 'a'],
    'hash 2^31-1, largest total' => [['SI7p-', ['a', 2147483646], ['b', 1]], 'a'],
    'hash 2^31-1 is the lower half' => [['SI7p-', ['a', 1], ['b', 1]], 'a'],
    'hash 2^31 is the upper half' => [['6LvT0', ['a', 1], ['b', 1]], 'b'],
    'hash 0' => [['ejOoVL', ['a', 1], ['b', 2147483646]], 'a'],
]);

it('yields null without a targeting key, so the default variant applies', function (mixed $targetingKey): void {
    expect(Fractional::evaluate(['$flagd' => ['flagKey' => 'exp'], 'targetingKey' => $targetingKey], [['a', 1]]))->toBeNull();
})->with([[null], [''], [0], [false], [[]]]);

it('reads a bucket key only from an object, as the reference does', function (mixed $data, mixed $expected): void {
    expect(Fractional::evaluate($data, [['a', 1]]))->toBe($expected);
})->with([
    'an empty object' => [new stdClass, null],
    'an empty PHP array, which counts as {}' => [[], null],
    'a targeting key alone' => [['targetingKey' => 'u'], 'a'],
    'an empty $flagd' => [['$flagd' => new stdClass, 'targetingKey' => 'u'], 'a'],
    'a flag key that is not text, without a targeting key' => [['$flagd' => ['flagKey' => 5]], null],
    'two numbers adding to zero (Python adds them)' => [['$flagd' => ['flagKey' => -1], 'targetingKey' => 1], null],
]);

it('fails where the reference raises', function (mixed $data): void {
    expect(fn (): mixed => Fractional::evaluate($data, [['a', 1]]))->toThrow(JsonLogicError::class);
})->with([
    'no data' => [null],
    'a number' => [1],
    'a list' => [[[1]]],
    '$flagd null' => [['$flagd' => null, 'targetingKey' => 'u']],
    '$flagd null without a targeting key' => [['$flagd' => null]],
    '$flagd a list' => [['$flagd' => ['x'], 'targetingKey' => 'u']],
    'flag key not text' => [['$flagd' => ['flagKey' => 5], 'targetingKey' => 'u']],
    'flag key null' => [['$flagd' => ['flagKey' => null], 'targetingKey' => 'u']],
    'targeting key not text' => [['targetingKey' => 5]],
    'targeting key a list' => [['targetingKey' => [1]]],
    'two numbers' => [['$flagd' => ['flagKey' => 1], 'targetingKey' => 1]],
]);

it('never reads the data when the bucket key is explicit', function (): void {
    expect(Fractional::evaluate(1, ['seed', ['a', 1]]))->toBe('a')
        ->and(Fractional::evaluate(null, ['seed', ['a', 1]]))->toBe('a');
});

it('reads the unsigned murmur3 hash mmh3 returns', function (string $key, int $hash): void {
    // The boundary keys of the flagd-testbed hash-edge scenario; every number is mmh3.hash(key, signed=False).
    expect(Fractional::hash($key))->toBe($hash);
})->with([
    'empty' => ['', 0],
    'a' => ['a', 1009084850],
    'hash 0' => ['ejOoVL', 0],
    'hash 1' => ['bY9fO-', 1],
    'hash 2^31-1' => ['SI7p-', 2147483647],
    'hash 2^31' => ['6LvT0', 2147483648],
    'hash 2^32-1' => ['ceQdGm', 4294967295],
    'UTF-8 bytes' => ['José', 3422137460],
    'flag key + targeting key' => ['expuser-42', 2205093680],
]);

it('registers the four flagd operators on the engine', function (): void {
    $logic = FlagdOperators::jsonLogic();

    expect($logic->apply(Json::decode('{"sem_ver":["2.0.1",">","2.0.0"]}')))->toBeTrue()
        ->and($logic->apply(Json::decode('{"starts_with":[{"var":"id"},"abc"]}'), ['id' => 'abcdef']))->toBeTrue()
        ->and($logic->apply(Json::decode('{"ends_with":[{"var":"id"},"xyz"]}'), ['id' => 'abcxyz']))->toBeTrue()
        ->and($logic->apply(Json::decode('{"fractional":[["only",1]]}'), ['$flagd' => ['flagKey' => 'f'], 'targetingKey' => 'u']))->toBe('only');
});

it('evaluates the arguments before an operator sees them', function (): void {
    $logic = FlagdOperators::jsonLogic();
    $data = ['$flagd' => ['flagKey' => 'f'], 'targetingKey' => 'u', 'tier' => 'premium', 'version' => 2];

    expect($logic->apply(Json::decode('{"fractional":[{"var":"targetingKey"},[{"var":"tier"},1]]}'), $data))->toBe('premium')
        ->and($logic->apply(Json::decode('{"sem_ver":[{"var":"version"},"^","2.9.1"]}'), $data))->toBeTrue();
});

it('makes the rule fail where the reference raises inside it', function (): void {
    // `some` hands each item to the operator as its data; an item that is not an object has no targeting key
    // to read, and Python's data.get() raises (GENERAL), where answering null would pick the other branch.
    expect(fn (): mixed => FlagdOperators::jsonLogic()->apply(
        Json::decode('{"if":[{"some":[[1],{"fractional":[["on",1]]}]},"a","b"]}'),
        ['$flagd' => ['flagKey' => 'f'], 'targetingKey' => 'u'],
    ))->toThrow(JsonLogicError::class);
});
