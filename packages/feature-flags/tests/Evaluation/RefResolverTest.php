<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Evaluation\RefResolver;

/*
 | `$ref` expansion (CONTRACT.md, the `$ref` paragraphs; the reference algorithm is expand_refs/bounded_size in
 | the conformance generator): structural and transitive, a missing name or a cycle left in place, and a flag over
 | 10 000 JSON values (every resolved reference costing one) or 128 nesting levels refused before anything is built.
 | Every boundary below was checked against that algorithm.
 */

/**
 * $leaf wrapped in $lists one-item lists.
 */
function featureFlagsNested(int $lists, mixed $leaf): mixed
{
    for ($i = 0; $i < $lists; $i++) {
        $leaf = [$leaf];
    }

    return $leaf;
}

it('counts every object, array and scalar of the expansion against the 10 000 value budget', function (): void {
    // {"and": [true × n]} is n + 2 values: the object, the list, n scalars.
    expect(RefResolver::withinLimits(['and' => array_fill(0, 9_998, true)], []))->toBeTrue()
        ->and(RefResolver::withinLimits(['and' => array_fill(0, 9_999, true)], []))->toBeFalse();
});

it('charges one value for every reference it resolves', function (): void {
    $evaluators = ['x' => ['and' => array_fill(0, 9_997, true)], 'y' => ['and' => array_fill(0, 9_998, true)]];

    expect(RefResolver::withinLimits(['$ref' => 'x'], $evaluators))->toBeTrue()
        ->and(RefResolver::withinLimits(['$ref' => 'y'], $evaluators))->toBeFalse();
});

it('counts a reference it cannot resolve as the object it is', function (): void {
    // {"and": [{"$ref": "nobody"} × n, true × m]}: 2 + 2n + m.
    $rule = static fn (int $scalars): array => ['and' => [['$ref' => 'nobody'], ['$ref' => 'nobody'], ...array_fill(0, $scalars, true)]];

    expect(RefResolver::withinLimits($rule(9_994), []))->toBeTrue()
        ->and(RefResolver::withinLimits($rule(9_995), []))->toBeFalse();
});

it('allows 128 nesting levels, the targeting object being the first', function (): void {
    // {"!!": [[…[true]…]]}: the object is level 1 and k lists reach level k + 1.
    expect(RefResolver::withinLimits(['!!' => featureFlagsNested(127, true)], []))->toBeTrue()
        ->and(RefResolver::withinLimits(['!!' => featureFlagsNested(128, true)], []))->toBeFalse()
        ->and(RefResolver::withinLimits(['!!' => featureFlagsNested(127, ['$ref' => 'nobody'])], []))->toBeFalse()
        ->and(RefResolver::withinLimits(['!!' => featureFlagsNested(126, ['$ref' => 'nobody'])], []))->toBeTrue();
});

it('puts a resolved reference in the place of its object without adding a level', function (): void {
    $evaluators = ['leaf' => ['!!' => [true]], 'alias' => ['$ref' => 'leaf']];

    expect(RefResolver::withinLimits(['!!' => featureFlagsNested(125, ['$ref' => 'alias'])], $evaluators))->toBeTrue()
        ->and(RefResolver::withinLimits(['!!' => featureFlagsNested(125, ['!!' => [true]])], $evaluators))->toBeTrue()
        ->and(RefResolver::withinLimits(['!!' => featureFlagsNested(126, ['$ref' => 'alias'])], $evaluators))->toBeFalse();
});

it('decides a fan-out or a chain of references within the budget, without building it', function (): void {
    $fan = ['fan-0' => ['==' => [['var' => 'tier'], 'gold']]];
    for ($i = 1; $i <= 60; $i++) {
        $fan["fan-{$i}"] = ['or' => [['$ref' => 'fan-'.($i - 1)], ['$ref' => 'fan-'.($i - 1)]]];
    }
    $chain = ['link-0' => true];
    for ($i = 1; $i <= 100_000; $i++) {
        $chain["link-{$i}"] = ['$ref' => 'link-'.($i - 1)];
    }

    $started = hrtime(true);
    $fanOut = RefResolver::withinLimits(['$ref' => 'fan-60'], $fan);
    $longChain = RefResolver::withinLimits(['$ref' => 'link-100000'], $chain);
    $shortChain = RefResolver::withinLimits(['$ref' => 'link-9998'], $chain);
    $seconds = (hrtime(true) - $started) / 1e9;

    expect([$fanOut, $longChain, $shortChain])->toBe([false, false, true])
        ->and($seconds)->toBeLessThan(2.0);
});

it('expands references structurally and transitively, whatever the names sort as', function (): void {
    $evaluators = Json::members(Json::decode('{
        "a-outer": {"and": [{"$ref": "z-inner"}, {"var": "y"}]},
        "z-inner": {"$ref": "m-leaf"},
        "m-leaf": {"in": ["C:\\\\temp\\\\new", {"var": "paths"}]}
    }'));

    $expanded = RefResolver::expand(Json::decode('{"if": [{"$ref": "a-outer"}, "on", null]}'), $evaluators);

    expect(Json::encode($expanded))->toBe('{"if":[{"and":[{"in":["C:\\\\temp\\\\new",{"var":"paths"}]},{"var":"y"}]},"on",null]}');
});

it('leaves a missing name and a cycle in place', function (): void {
    $evaluators = ['a' => ['or' => [['$ref' => 'b'], false]], 'b' => ['or' => [['$ref' => 'a'], false]]];

    expect(Json::encode(RefResolver::expand(['and' => [['$ref' => 'nobody'], ['$ref' => 'a']]], $evaluators)))
        ->toBe('{"and":[{"$ref":"nobody"},{"or":[{"or":[{"$ref":"a"},false]},false]}]}');
});

it('reads a reference only as an object with one text $ref member', function (): void {
    $evaluators = ['x' => ['var' => 'x']];

    expect(Json::encode(RefResolver::expand(['and' => [['$ref' => 'x', 'other' => 1], ['$ref' => 1], ['$ref' => 'x']]], $evaluators)))
        ->toBe('{"and":[{"$ref":"x","other":1},{"$ref":1},{"var":"x"}]}');
});

it('builds new objects and never touches the evaluators it reads', function (): void {
    $evaluators = Json::members(Json::decode('{"shared": {"!!": [{"0": {"$ref": "inner"}, "1": 2}]}, "inner": {"var": "x"}, "empty": {}}'));
    $before = Json::encode($evaluators);

    $expanded = Json::members(RefResolver::expand(['or' => [['$ref' => 'shared'], ['$ref' => 'empty'], ['$ref' => 'empty']]], $evaluators));
    /** @var list<mixed> $branches */
    $branches = $expanded['or'];

    expect(Json::encode($evaluators))->toBe($before)
        ->and(Json::encode($expanded))->toBe('{"or":[{"!!":[{"0":{"var":"x"},"1":2}]},{},{}]}')
        ->and($branches[1])->toBeInstanceOf(stdClass::class)
        ->and($branches[1] === $branches[2])->toBeFalse()
        ->and($branches[1] === $evaluators['empty'])->toBeFalse();
});

it('expands a reference to a null rule to null', function (): void {
    expect(RefResolver::expand(['$ref' => 'nothing'], ['nothing' => null]))->toBeNull();
});
