<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Evaluation\JsonLogic;
use Firefly\FeatureFlags\Evaluation\JsonLogicError;
use Firefly\FeatureFlags\Evaluation\UnknownOperator;

/*
 | Every row's expected value is what the reference engine (panzi-json-logic 1.0.1, the one
 | openfeature-flagd-core 1.0.0 runs) returns for the same logic and data. A row that fails here is a place
 | where PHP and Python services would disagree about a flag.
 */
dataset('reference json logic', [
    'var dot path' => ['{"var":"user.name"}', '{"user":{"name":"ana"}}', '"ana"'],
    'var list index' => ['{"var":"profiles.0"}', '{"profiles":["staging","eu"]}', '"staging"'],
    'var numeric key on list' => ['{"var":1}', '["a","b"]', '"b"'],
    'var length of list' => ['{"var":"roles.length"}', '{"roles":["a","b","c"]}', '3'],
    'var length of string' => ['{"var":"name.length"}', '{"name":"José"}', '4'],
    'var missing with default' => ['{"var":["plan","free"]}', '{}', '"free"'],
    'var empty key returns data' => ['{"var":""}', '{"a":1}', '{"a":1}'],
    'var leading zero index' => ['{"var":"xs.01"}', '{"xs":[1,2]}', 'null'],
    'var char of string' => ['{"var":"name.1"}', '{"name":"José"}', '"o"'],
    'missing' => ['{"missing":["a","b","c"]}', '{"a":1,"b":""}', '["b","c"]'],
    'missing_some ok' => ['{"missing_some":[1,["a","b"]]}', '{"a":1}', '[]'],
    'missing_some short' => ['{"missing_some":[2,["a","b","c"]]}', '{"a":1}', '["b","c"]'],
    'if chain' => ['{"if":[false,"a",null,"b","c"]}', '{}', '"c"'],
    'if two args false' => ['{"if":[false,"a"]}', '{}', 'null'],
    'if empty' => ['{"if":[]}', '{}', 'null'],
    'ternary' => ['{"?:":[true,"y","n"]}', '{}', '"y"'],
    'loose eq number string' => ['{"==":[1,"1"]}', '{}', 'true'],
    'loose eq bool number' => ['{"==":[true,1]}', '{}', 'true'],
    'loose eq null null' => ['{"==":[null,null]}', '{}', 'true'],
    'loose eq null zero' => ['{"==":[null,0]}', '{}', 'true'],
    'loose eq string list' => ['{"==":["a,b",["a","b"]]}', '{}', 'true'],
    'loose eq list list' => ['{"==":[[1],[1]]}', '{}', 'false'],
    'strict eq int float' => ['{"===":[1,1.0]}', '{}', 'true'],
    'strict eq true one' => ['{"===":[true,1]}', '{}', 'true'],
    'strict eq string number' => ['{"===":["1",1]}', '{}', 'false'],
    'strict ne' => ['{"!==":["1",1]}', '{}', 'true'],
    'not empty list' => ['{"!":[[]]}', '{}', 'true'],
    'double not string zero' => ['{"!!":["0"]}', '{}', 'true'],
    'double not empty object' => ['{"!!":[{}]}', '{}', 'true'],
    'and returns first falsy' => ['{"and":[1,0,2]}', '{}', '0'],
    'and returns last' => ['{"and":[1,"a"]}', '{}', '"a"'],
    'or returns first truthy' => ['{"or":[0,"","x"]}', '{}', '"x"'],
    'or returns last' => ['{"or":[0,false]}', '{}', 'false'],
    'lt number string' => ['{"<":[1,"2"]}', '{}', 'true'],
    'lt strings' => ['{"<":["apple","banana"]}', '{}', 'true'],
    'lt string number numeric' => ['{"<":["10",9]}', '{}', 'false'],
    'between' => ['{"<":[1,2,3]}', '{}', 'true'],
    'between false' => ['{"<=":[1,4,3]}', '{}', 'false'],
    'gte' => ['{">=":[3,3]}', '{}', 'true'],
    'gt null' => ['{">":[1,null]}', '{}', 'true'],
    'max' => ['{"max":[1,3,2]}', '{}', '3'],
    'min strings' => ['{"min":["3",1]}', '{}', '1'],
    'plus ints' => ['{"+":[1,2]}', '{}', '3'],
    'plus string' => ['{"+":["1",2]}', '{}', '3.0'],
    'plus bool' => ['{"+":[true,2]}', '{}', '3'],
    'times' => ['{"*":[2,"3"]}', '{}', '6.0'],
    'minus unary' => ['{"-":[5]}', '{}', '-5'],
    'minus binary' => ['{"-":[5,7]}', '{}', '-2'],
    'divide ints' => ['{"/":[6,3]}', '{}', '2.0'],
    'modulo negative' => ['{"%":[-7,3]}', '{}', '2'],
    'modulo float' => ['{"%":[7.5,-2]}', '{}', '-0.5'],
    'in list' => ['{"in":["beta",["alpha","beta"]]}', '{}', 'true'],
    'in list loose number' => ['{"in":[1,[1.0,2]]}', '{}', 'true'],
    'in string' => ['{"in":["sp","spring"]}', '{}', 'true'],
    'in string number' => ['{"in":[1,"a1b"]}', '{}', 'true'],
    'in neither' => ['{"in":["a",5]}', '{}', 'false'],
    'cat mixed' => ['{"cat":["v",1,2.5,true,null,[1,2]]}', '{}', '"v12.5Truenull1,2"'],
    'cat float whole' => ['{"cat":[1.0,"|",1e+20,"|",0.0001,"|",1e-05]}', '{}', '"1|1e+20|0.0001|1e-05"'],
    'substr neg' => ['{"substr":["jsonlogic",-5]}', '{}', '"logic"'],
    'substr len' => ['{"substr":["jsonlogic",1,3]}', '{}', '"son"'],
    'substr neg len' => ['{"substr":["jsonlogic",4,-2]}', '{}', '"log"'],
    'merge' => ['{"merge":[[1,2],3,[[4]]]}', '{}', '[1,2,3,[4]]'],
    'map' => ['{"map":[{"var":"xs"},{"*":[{"var":""},2]}]}', '{"xs":[1,2,3]}', '[2,4,6]'],
    'filter' => ['{"filter":[{"var":"xs"},{">":[{"var":""},1]}]}', '{"xs":[1,2,3]}', '[2,3]'],
    'reduce' => ['{"reduce":[{"var":"xs"},{"+":[{"var":"current"},{"var":"accumulator"}]},10]}', '{"xs":[1,2,3]}', '16'],
    'reduce not list' => ['{"reduce":[{"var":"nope"},{"+":[1,1]},7]}', '{}', '7'],
    'all empty' => ['{"all":[[],{">":[{"var":""},0]}]}', '{}', 'false'],
    'all' => ['{"all":[[1,2],{">":[{"var":""},0]}]}', '{}', 'true'],
    'some' => ['{"some":[[0,2],{">":[{"var":""},1]}]}', '{}', 'true'],
    'none' => ['{"none":[[0,1],{">":[{"var":""},1]}]}', '{}', 'true'],
    'multi key object is data' => ['{"a":1,"b":2}', '{}', '{"a":1,"b":2}'],
    'empty object is truthy' => ['{"if":[{},"yes","no"]}', '{}', '"yes"'],
    'empty list is falsy' => ['{"if":[[],"yes","no"]}', '{}', '"no"'],
    'log returns arg' => ['{"log":["x"]}', '{}', '"x"'],
    'to number infinity string' => ['{">":["infinity",1e+300]}', '{}', 'true'],
    'to number underscores' => ['{"+":["1_000",0]}', '{}', '1000.0'],
]);

/*
 | Edges the plan's table does not reach, each checked against the same reference engine: places where a
 | straight PHP port would answer differently (Python keeps a boolean through min/max, compares an int with a
 | float exactly, signs a zero remainder like the divisor, reads Unicode digits and spaces in float()) or would
 | raise a PHP warning instead of answering (a float index far outside the int range).
 */
dataset('reference json logic edges', [
    'max of booleans keeps a boolean' => ['{"max":[true,false]}', '{}', 'true'],
    'min of a boolean before an equal number' => ['{"min":[false,0]}', '{}', 'false'],
    'max of a one-item boolean list' => ['{"max":[[true],0]}', '{}', 'true'],
    'max keeps the first of equals' => ['{"max":[1,true]}', '{}', '1'],
    'modulo zero takes the divisor sign' => ['{"%":[4.0,-2]}', '{}', '-0.0'],
    'modulo zero with a positive divisor' => ['{"%":[-4.0,2]}', '{}', '0.0'],
    'modulo ints both negative' => ['{"%":[-7,-3]}', '{}', '-1'],
    'modulo int by float' => ['{"%":[7,2.5]}', '{}', '2.0'],
    'divide to a fraction' => ['{"/":[7,2]}', '{}', '3.5'],
    'var numeric key on an object is the default' => ['{"var":[1,"d"]}', '{"1":"x"}', '"d"'],
    'var digit text key on an object' => ['{"var":"1"}', '{"1":"x"}', '"x"'],
    'var boolean key on a list' => ['{"var":true}', '["a","b"]', '"b"'],
    'var fractional key' => ['{"var":1.5}', '["a","b"]', 'null'],
    'var whole float key' => ['{"var":1.0}', '["a","b"]', '"b"'],
    'var huge float key' => ['{"var":[1e300,"d"]}', '["a"]', '"d"'],
    'var length member of an object' => ['{"var":"a.length"}', '{"a":{"length":9}}', '9'],
    'var through null' => ['{"var":["a.b","d"]}', '{"a":null}', '"d"'],
    'substr huge index' => ['{"substr":["abc",1e300]}', '{}', '""'],
    'substr huge negative index' => ['{"substr":["abc",-1e300]}', '{}', '"abc"'],
    'substr minus infinity index' => ['{"substr":["abc","-infinity"]}', '{}', '"abc"'],
    'substr huge length' => ['{"substr":["abc",1,1e300]}', '{}', '"bc"'],
    'substr huge negative length' => ['{"substr":["abc",1,-1e300]}', '{}', '""'],
    'substr counts code points' => ['{"substr":["José!",3,1]}', '{}', '"é"'],
    'loose eq beyond 2^53' => ['{"==":[9007199254740993,9007199254740992.0]}', '{}', 'false'],
    'lt beyond 2^53' => ['{"<":[9007199254740992.0,9007199254740993]}', '{}', 'true'],
    'strict eq beyond 2^53' => ['{"===":[9007199254740993,9007199254740992.0]}', '{}', 'false'],
    'to number unicode digits' => ['{"+":["٤٢",0]}', '{}', '42.0'],
    'to number unicode spaces' => ['{"+":[" 7 ",0]}', '{}', '7.0'],
    'to number inner space' => ['{"cat":[{"+":["1 000",0]}]}', '{}', '"nan"'],
    'to number full grammar' => ['{"cat":[{"+":[" +1_0.5e1 ",0]}]}', '{}', '"105"'],
    'loose eq null false' => ['{"==":[null,false]}', '{}', 'true'],
    'loose eq empty list empty string' => ['{"==":[[],""]}', '{}', 'true'],
    'loose eq two empty objects' => ['{"==":[{},{}]}', '{}', 'false'],
    'cat nested booleans' => ['{"cat":[[true,[false]]]}', '{}', '"True,False"'],
    'cat object' => ['{"cat":[{"var":""}]}', '{}', '"[object Object]"'],
    'cat fifteen significant digits' => ['{"cat":[1e15,"|",1e16,"|",123456789012345678,"|",0.1,"|",-0.0,"|",5e-324]}', '{}', '"1e+15|1e+16|123456789012345678|0.1|-0|4.94065645841247e-324"'],
    'cat infinite remainder' => ['{"cat":[{"%":[-5,"infinity"]}]}', '{}', '"inf"'],
    'in list python equality' => ['{"in":[true,[1]]}', '{}', 'true'],
    'strict eq deep python equality' => ['{"===":[{"var":"x"},{"var":"y"}]}', '{"x":[1,{"a":true}],"y":[1.0,{"a":1}]}', 'true'],
    'reduce initial is not evaluated' => ['{"reduce":[[1],{"var":"accumulator"},{"var":"x"}]}', '{"x":5}', '{"var":"x"}'],
    'minus with a null second argument negates' => ['{"-":[1,null]}', '{}', '-1'],
    'minus of nothing' => ['{"-":[]}', '{}', '0'],
    'missing_some null need' => ['{"missing_some":[null,["a"]]}', '{}', '[]'],
    'lt null text' => ['{"<":[null,"a"]}', '{}', 'false'],
    'lt one-item lists' => ['{"<":[[1],[2]]}', '{}', 'true'],
    'not of nothing' => ['{"!":[]}', '{}', 'true'],
    'not of an empty object' => ['{"!":{}}', '{}', 'false'],
    'unknown operator in an untaken branch' => ['{"if":[true,"a",{"nope":[]}]}', '{}', '"a"'],
    'loose eq one object with itself' => ['{"==":[{"var":"o"},{"var":"o"}]}', '{"o":{}}', 'true'],
    'loose eq two equal objects' => ['{"==":[{"var":"o"},{"var":"p"}]}', '{"o":{},"p":{}}', 'false'],
]);

it('applies JSON Logic exactly as the reference engine does', function (string $logic, string $data, string $expected): void {
    $result = (new JsonLogic)->apply(Json::decode($logic), Json::decode($data));

    expect(Json::canonical($result))->toBe(Json::canonical(Json::decode($expected)));
})->with('reference json logic');

it('applies the edges exactly as the reference engine does', function (string $logic, string $data, string $expected): void {
    $result = (new JsonLogic)->apply(Json::decode($logic), Json::decode($data));

    expect(Json::canonical($result))->toBe(Json::canonical(Json::decode($expected)));
})->with('reference json logic edges');

it('turns "inf" into NaN but reads "infinity" as infinity, as Python float() does', function (): void {
    $logic = new JsonLogic;
    $sum = $logic->apply(Json::decode('{"+":["inf",1]}'));

    expect(is_float($sum) && is_nan($sum))->toBeTrue()
        ->and($logic->apply(Json::decode('{">":["infinity",1e300]}')))->toBeTrue();
});

it('refuses an operator nobody registered as an UnknownOperator', function (string $logic): void {
    expect(fn () => (new JsonLogic)->apply(Json::decode($logic), []))->toThrow(UnknownOperator::class);
})->with([
    'unknown name' => ['{"nope":[1]}'],
    'dotted name' => ['{"a.b":[1]}'],
    'unresolved $ref' => ['{"$ref":"missing"}'],
    'nested inside if' => ['{"if":[{"$ref":"missing"},"a","b"]}'],
    'list-like name' => ['{"0":[1]}'],
    'empty name' => ['{"":[1]}'],
]);

it('names the operator nobody registered', function (): void {
    $caught = null;
    try {
        (new JsonLogic)->apply(Json::decode('{"$ref":"segment"}'));
    } catch (UnknownOperator $unknown) {
        $caught = $unknown;
    }

    expect($caught?->operator)->toBe('$ref')
        ->and($caught?->getMessage())->toBe('Unrecognized operation [$ref].');
});

it('fails at run time as a JsonLogicError (GENERAL), never as an UnknownOperator or a PHP error', function (string $logic): void {
    $caught = null;
    try {
        (new JsonLogic)->apply(Json::decode($logic), []);
    } catch (JsonLogicError $failure) {
        $caught = $failure;
    }

    // GENERAL, not PARSE_ERROR: the reference raises ZeroDivisionError / OverflowError / TypeError here, never
    // the ReferenceError flagd-core maps to PARSE_ERROR.
    expect($caught)->toBeInstanceOf(JsonLogicError::class)
        ->and($caught)->not->toBeInstanceOf(UnknownOperator::class);
})->with([
    'divide by zero' => ['{"/":[1,0]}'],
    'modulo by zero' => ['{"%":[1,0]}'],
    'divide by zero text' => ['{"/":[1,"0.0"]}'],
    'modulo by float zero' => ['{"%":[1,0.0]}'],
    'divide by negative zero' => ['{"/":[1,-0.0]}'],
    'substr infinite index' => ['{"substr":["abc","infinity"]}'],
    'substr infinite length' => ['{"substr":["abc",0,"-infinity"]}'],
    'var infinite index on a list' => ['{"var":1e400}'],
    'dotted name under a builtin' => ['{"in.x":[1]}'],
    'dotted name with an empty member' => ['{"var.":["a"]}'],
    'var with a third argument' => ['{"var":["a","b","c"]}'],
]);

it('runs a registered operator with its evaluated arguments and the data', function (): void {
    $logic = new JsonLogic(['twice' => static fn (mixed $data, array $args): mixed => [$args, Json::members($data)['n'] ?? null]]);

    expect($logic->apply(Json::decode('{"twice":[{"var":"n"},2]}'), ['n' => 7]))->toBe([[7, 2], 7]);
});

it('lets a registered operator replace a builtin of the same name, as the reference operation table does', function (): void {
    $logic = new JsonLogic(['cat' => static fn (mixed $data, array $args): mixed => 'replaced']);

    expect($logic->apply(Json::decode('{"cat":["a","b"]}')))->toBe('replaced');
});

it('fails a dotted name under a registered operator as a JsonLogicError, not an UnknownOperator', function (): void {
    $logic = new JsonLogic(['twice' => static fn (mixed $data, array $args): mixed => $args]);
    $caught = null;
    try {
        $logic->apply(Json::decode('{"twice.x":[1]}'));
    } catch (JsonLogicError $failure) {
        $caught = $failure;
    }

    expect($caught)->toBeInstanceOf(JsonLogicError::class)
        ->and($caught)->not->toBeInstanceOf(UnknownOperator::class);
});

it('reports a registered operator that throws as a JsonLogicError carrying the failure', function (): void {
    $logic = new JsonLogic(['boom' => static fn (mixed $data, array $args): mixed => throw new TypeError('bad argument')]);
    $caught = null;
    try {
        $logic->apply(Json::decode('{"boom":[1]}'));
    } catch (JsonLogicError $failure) {
        $caught = $failure;
    }

    expect($caught)->toBeInstanceOf(JsonLogicError::class)
        ->and($caught)->not->toBeInstanceOf(UnknownOperator::class)
        ->and($caught?->getPrevious())->toBeInstanceOf(TypeError::class)
        ->and($caught?->getMessage())->toContain('bad argument');
});

it('evaluates logic nested past the 128 levels the evaluator admits', function (): void {
    $logic = true;
    for ($level = 0; $level < 300; $level++) {
        $logic = ['!' => [$logic]];
    }

    expect((new JsonLogic)->apply($logic))->toBeTrue();
});

it('stops logic nested past its own depth limit with a JsonLogicError, never a PHP error', function (): void {
    $logic = true;
    for ($level = 0; $level < 5000; $level++) {
        $logic = ['!' => [$logic]];
    }
    $caught = null;
    try {
        (new JsonLogic)->apply($logic);
    } catch (JsonLogicError $failure) {
        $caught = $failure;
    }

    expect($caught)->toBeInstanceOf(JsonLogicError::class)
        ->and($caught)->not->toBeInstanceOf(UnknownOperator::class);
});

it('answers truthiness the way the reference does', function (mixed $value, bool $truthy): void {
    expect(JsonLogic::truthy($value))->toBe($truthy);
})->with([
    [null, false], [false, false], [0, false], [0.0, false], [NAN, false], ['', false], [[], false],
    ['0', true], [' ', true], [1, true], [-1.5, true], [[0], true], [new stdClass, true], [['k' => null], true],
]);

it('reads numbers the way the reference to_number() does', function (mixed $value, int|float $number): void {
    expect(JsonLogic::toNumber($value))->toBe($number);
})->with([
    'true' => [true, 1],
    'null' => [null, 0],
    'empty list' => [[], 0],
    'one-item list' => [['3'], 3.0],
    'underscores' => ['1_000', 1000.0],
    'padded' => [" 12\t", 12.0],
    'exponent' => ['1E5', 100000.0],
    'leading point' => ['.5', 0.5],
    'trailing point' => ['5.', 5.0],
    'infinity' => ['Infinity', INF],
    'minus infinity' => ['-infinity', -INF],
    'unicode digits' => ["\u{0664}\u{0662}", 42.0],
    'unicode spaces' => ["\u{3000}7\u{2003}", 7.0],
    'long digit run' => [str_repeat('3', 100000), INF],
    'long unicode digit run' => [str_repeat("\u{0663}", 100000), INF],
    'long underscored run' => [str_repeat('1_', 50000).'1', INF],
]);

it('reads unparsable text, objects and longer lists as NaN', function (mixed $value): void {
    $number = JsonLogic::toNumber($value);

    expect(is_float($number) && is_nan($number))->toBeTrue();
})->with([
    'inf' => ['inf'], 'plus inf' => ['+inf'], 'padded minus inf' => [' -INF '], 'nan' => ['nan'],
    'double underscore' => ['1__0'], 'leading underscore' => ['_1'], 'trailing underscore' => ['1_'],
    'hex' => ['0x10'], 'inner space' => ['1 0'], 'empty' => [''], 'two items' => [[1, 2]], 'object' => [new stdClass],
    'underscore before the point' => ['1_.5'], 'file separator float() keeps' => ["\x1C1"], 'other script letters' => ["\u{0663}a"],
    'not UTF-8' => ["\xFF1"], 'long run of inner spaces' => ['1'.str_repeat(' ', 100000).'x'],
]);

it('writes text the way the reference to_string() does', function (mixed $value, string $text): void {
    expect(JsonLogic::toStr($value))->toBe($text);
})->with([
    'true' => [true, 'True'],
    'false' => [false, 'False'],
    'null' => [null, 'null'],
    'whole float' => [1.0, '1'],
    'rounded sum' => [0.1 + 0.2, '0.3'],
    'large float' => [1e20, '1e+20'],
    'fifteen digits' => [123456789012345.0, '123456789012345'],
    'sixteen digits' => [1e15, '1e+15'],
    'small float' => [0.0001, '0.0001'],
    'smaller float' => [0.00001, '1e-05'],
    'negative zero' => [-0.0, '-0'],
    'nan' => [NAN, 'nan'],
    'infinity' => [INF, 'inf'],
    'minus infinity' => [-INF, '-inf'],
    'large int' => [123456789012345678, '123456789012345678'],
    'nested list' => [[1, [true, null]], '1,True,null'],
    'object' => [['k' => 1], '[object Object]'],
    'empty object' => [new stdClass, '[object Object]'],
]);
