<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Evaluation;

use Closure;
use Firefly\FeatureFlags\Definition\Json;
use Throwable;

/**
 * JSON Logic, ported from the engine the reference evaluator runs (panzi-json-logic 1.0.1 under
 * openfeature-flagd-core 1.0.0) rather than from json-logic-js, because the contract is "the same answer as
 * the reference" and the two engines disagree at the edges:
 *
 *  - truthiness is Python's with JavaScript's empty-array rule: null, false, 0, 0.0, NaN, "" and [] are
 *    falsy; "0" and every object — {} included — are truthy;
 *  - `===` is Python `==` (1 === 1.0, true === 1, deep on lists and objects), and `==` is JSON Logic's loose
 *    equality, in which two lists or two objects are equal only when they are the same one; numbers compare
 *    exactly, as Python compares an int with a float (9007199254740993 is not 9007199254740992.0);
 *  - to_string() of a boolean is "True"/"False" (Python's bool IS an int, so panzi's int arm wins), a float is
 *    `%.15g`, a list joins with "," and an object is "[object Object]";
 *  - to_number() of a string is Python's float() (underscores between digits, "infinity", "nan", Unicode
 *    decimal digits, surrounding Unicode spaces) except "inf"/"+inf"/"-inf", which are NaN; a boolean stays a
 *    boolean through `min`/`max`; `/` always yields a float and a zero divisor is an error; `%` takes the
 *    divisor's sign, a zero remainder too; an infinite index or length (`int(inf)`) is an error;
 *  - `reduce` starts from its third argument as written, not evaluated, and `var` refuses a third argument (the
 *    reference's op_var() takes no extra ones, so Python raises a TypeError: GENERAL);
 *  - an operator name nobody registered raises UnknownOperator, which the evaluator maps to PARSE_ERROR (an
 *    unresolved {"$ref": …} lands here); a dotted name under a known operator (`in.x`) and every other failure
 *    is a JsonLogicError (GENERAL), as the reference's TypeError, ZeroDivisionError and OverflowError are.
 *
 * Where PHP cannot follow the reference (each is outside what a flag rule writes in practice):
 *
 *  - `==` of two lists, or of two objects decoded as arrays, is always false: the reference compares identity
 *    (`a is b`) and a PHP array has none, so a rule comparing the same `var` path with itself is true there and
 *    false here (a stdClass — `{}`, `{"0": …}` — does have one, and compares by it);
 *  - integers are 64-bit: `+`, `-` and `*` past PHP_INT_MAX turn into a float where Python stays exact, `/`
 *    rounds an integer beyond ±2^53 to a float before dividing (Python rounds the exact quotient, so the last
 *    bit can differ), and an integer literal beyond 64 bits is a float already when decoded;
 *  - the reference's date/datetime support has no JSON form; a PHP object other than stdClass is an object
 *    ("[object Object]", NaN), and text that is not UTF-8 (which Python text cannot be) reads as NaN;
 *  - `log` returns its argument and writes nothing (spec §10: data is never code, no I/O).
 *
 * Every failure is a JsonLogicError, whatever the input: logic nested deeper than MAX_DEPTH stops with one
 * rather than exhausting the stack (the reference's RecursionError is GENERAL too), and a registered operator
 * that throws anything else is wrapped in one.
 */
final class JsonLogic
{
    /**
     * How deep apply() recurses before it gives up: far past the 128 levels the evaluator admits for a flag's
     * expanded targeting, about where the reference's own recursion limit stops it.
     */
    public const int MAX_DEPTH = 1000;

    /** The reference's operation table; if, ?:, and, or, filter, map, reduce, all, some and none are special forms. */
    private const array BUILTINS = [
        '==', '!=', '===', '!==', '<', '>', '<=', '>=', '!', '!!', '+', '*', '-', '/', '%',
        'in', 'min', 'max', 'cat', 'log', 'var', 'substr', 'merge', 'missing', 'missing_some',
    ];

    /** The whitespace float() strips: ASCII spaces, and every non-ASCII str.isspace() character. */
    private const array FLOAT_SPACE = [
        "\t", "\n", "\x0B", "\x0C", "\r", ' ', "\u{85}", "\u{A0}", "\u{1680}", "\u{2000}", "\u{2001}", "\u{2002}", "\u{2003}",
        "\u{2004}", "\u{2005}", "\u{2006}", "\u{2007}", "\u{2008}", "\u{2009}", "\u{200A}", "\u{2028}", "\u{2029}", "\u{202F}",
        "\u{205F}", "\u{3000}",
    ];

    /** Python's str.strip() whitespace (every str.isspace() character), which to_number() strips before its "inf" check. */
    private const array STRIP_SPACE = [...self::FLOAT_SPACE, "\x1C", "\x1D", "\x1E", "\x1F"];

    /**
     * float()'s decimal grammar on lower-cased ASCII text without its underscores. Only single-character repeats:
     * a repeated group would recurse once per repetition and fail on a long run of digits.
     */
    private const string FLOAT_SYNTAX = '/^[+-]?(?:[0-9]+(?:\.[0-9]*)?|\.[0-9]+)(?:e[+-]?[0-9]+)?$/D';

    /** @var array<string, string> the ASCII digit of each Unicode decimal digit met so far (a pure function of it) */
    private static array $digits = [];

    /**
     * @param  array<string, Closure(mixed, list<mixed>): mixed>  $operations  flagd's operators, keyed by name; a
     *                                                                         name shared with a builtin replaces it
     */
    public function __construct(private readonly array $operations = []) {}

    /**
     * @throws UnknownOperator
     * @throws JsonLogicError
     */
    public function apply(mixed $logic, mixed $data = null): mixed
    {
        return $this->evaluate($logic, $data, 0);
    }

    public static function truthy(mixed $value): bool
    {
        return match (true) {
            $value === null => false,
            is_bool($value) => $value,
            is_float($value) => ! is_nan($value) && $value !== 0.0,
            is_int($value) => $value !== 0,
            is_string($value) => $value !== '',
            Json::isList($value) => $value !== [],
            default => true,
        };
    }

    public static function toNumber(mixed $value): int|float
    {
        $number = self::numeric($value);

        return is_bool($number) ? (int) $number : $number;
    }

    public static function toStr(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 'True' : 'False';
        }
        if (is_float($value)) {
            return self::formatG15($value);
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if ($value === null) {
            return 'null';
        }
        if (Json::isList($value)) {
            $parts = [];
            foreach ($value as $item) {
                $parts[] = self::toStr($item);
            }

            return implode(',', $parts);
        }

        return '[object Object]';
    }

    public static function looseEquals(mixed $a, mixed $b): bool
    {
        $kindA = self::kind($a);
        $kindB = self::kind($b);

        if ($kindA === $kindB) {
            // Two lists or two objects: the reference asks `a is b`. A stdClass has an identity to compare; an
            // array is a value and has none, so two arrays are never the same one.
            return $kindA === 'list' || $kindA === 'object' ? is_object($a) && $a === $b : $a === $b;
        }

        if (self::isNumeric($a) || self::isNumeric($b)) {
            return self::compareNumbers(self::toNumber($a), self::toNumber($b)) === 0;
        }

        if ($a === null || $b === null) {
            return false;
        }

        if (is_string($a)) {
            return $a === self::toStr($b);
        }

        if (is_string($b)) {
            return self::toStr($a) === $b;
        }

        return false;
    }

    public static function strictEquals(mixed $a, mixed $b): bool
    {
        if (self::isNumeric($a) && self::isNumeric($b)) {
            return self::compareNumbers(self::toNumber($a), self::toNumber($b)) === 0;
        }

        if (Json::isList($a) && Json::isList($b)) {
            if (count($a) !== count($b)) {
                return false;
            }
            foreach ($a as $index => $item) {
                if (! self::strictEquals($item, $b[$index])) {
                    return false;
                }
            }

            return true;
        }

        if (Json::isObject($a) && Json::isObject($b)) {
            $left = Json::members($a);
            $right = Json::members($b);
            if (count($left) !== count($right)) {
                return false;
            }
            foreach ($left as $key => $item) {
                if (! array_key_exists($key, $right) || ! self::strictEquals($item, $right[$key])) {
                    return false;
                }
            }

            return true;
        }

        return $a === $b;
    }

    /**
     * @throws UnknownOperator
     * @throws JsonLogicError
     */
    private function evaluate(mixed $logic, mixed $data, int $depth): mixed
    {
        if ($depth >= self::MAX_DEPTH) {
            throw new JsonLogicError('JSON Logic nests deeper than '.self::MAX_DEPTH.' levels.');
        }
        $depth++;

        if (Json::isList($logic)) {
            $items = [];
            foreach ($logic as $item) {
                $items[] = $this->evaluate($item, $data, $depth);
            }

            return $items;
        }

        $operation = self::operation($logic);
        if ($operation === null) {
            return $logic;
        }

        [$op, $args] = $operation;

        switch ($op) {
            case 'if':
            case '?:':
                return $this->conditional($args, $data, $depth);
            case 'and':
                $current = null;
                foreach ($args as $arg) {
                    $current = $this->evaluate($arg, $data, $depth);
                    if (! self::truthy($current)) {
                        return $current;
                    }
                }

                return $current;
            case 'or':
                $current = null;
                foreach ($args as $arg) {
                    $current = $this->evaluate($arg, $data, $depth);
                    if (self::truthy($current)) {
                        return $current;
                    }
                }

                return $current;
            case 'filter':
                if (count($args) < 2) {
                    return [];
                }
                $items = $this->evaluate($args[0], $data, $depth);
                if (! Json::isList($items)) {
                    return [];
                }
                $kept = [];
                foreach ($items as $item) {
                    if (self::truthy($this->evaluate($args[1], $item, $depth))) {
                        $kept[] = $item;
                    }
                }

                return $kept;
            case 'map':
                if ($args === []) {
                    return [];
                }
                $items = $this->evaluate($args[0], $data, $depth);
                if (! Json::isList($items)) {
                    return [];
                }
                $mapped = [];
                foreach ($items as $item) {
                    $mapped[] = $this->evaluate($args[1] ?? null, $item, $depth);
                }

                return $mapped;
            case 'reduce':
                if ($args === []) {
                    return null;
                }
                $items = $this->evaluate($args[0], $data, $depth);
                // The initial accumulator is the third argument as written: the reference does not evaluate it.
                $accumulator = $args[2] ?? null;
                if (! Json::isList($items)) {
                    return $accumulator;
                }
                foreach ($items as $item) {
                    $accumulator = $this->evaluate($args[1] ?? null, ['accumulator' => $accumulator, 'current' => $item], $depth);
                }

                return $accumulator;
            case 'all':
                if (count($args) < 2) {
                    return false;
                }
                $items = $this->evaluate($args[0], $data, $depth);
                if (! Json::isList($items) || $items === []) {
                    return false;
                }
                foreach ($items as $item) {
                    if (! self::truthy($this->evaluate($args[1], $item, $depth))) {
                        return false;
                    }
                }

                return true;
            case 'some':
            case 'none':
                if (count($args) < 2) {
                    return $op === 'none';
                }
                $items = $this->evaluate($args[0], $data, $depth);
                if (! Json::isList($items)) {
                    return $op === 'none';
                }
                foreach ($items as $item) {
                    if (self::truthy($this->evaluate($args[1], $item, $depth))) {
                        return $op === 'some';
                    }
                }

                return $op === 'none';
        }

        $values = [];
        foreach ($args as $arg) {
            $values[] = $this->evaluate($arg, $data, $depth);
        }

        if (isset($this->operations[$op])) {
            return $this->registered($op, $data, $values);
        }

        $a = $values[0] ?? null;
        $b = $values[1] ?? null;
        $c = $values[2] ?? null;

        return match ($op) {
            '==' => self::looseEquals($a, $b),
            '!=' => ! self::looseEquals($a, $b),
            '===' => self::strictEquals($a, $b),
            '!==' => ! self::strictEquals($a, $b),
            '<' => self::chain($a, $b, $c, -1, -1),
            '<=' => self::chain($a, $b, $c, -1, 0),
            '>' => self::chain($a, $b, $c, 1, 1),
            '>=' => self::chain($a, $b, $c, 1, 0),
            '!' => ! self::truthy($a),
            '!!' => self::truthy($a),
            '+' => self::sum($values),
            '*' => self::product($values),
            '-' => $b === null ? -self::toNumber($a) : self::toNumber($a) - self::toNumber($b),
            '/' => self::divide($a, $b),
            '%' => self::modulo($a, $b),
            'min' => self::extreme($values, -1),
            'max' => self::extreme($values, 1),
            'in' => self::in($a, $b),
            'cat' => self::concatenate($values),
            'substr' => self::substr($a, $b, $c),
            'merge' => self::merge($values),
            'var' => count($values) > 2
                ? throw new JsonLogicError('var takes a key and a default, not '.count($values).' arguments.')
                : self::variable($data, $a, $b),
            'missing' => self::missing($data, $values),
            'missing_some' => self::missingSome($data, $values[0] ?? 0, $b),
            'log' => $a,
            default => $this->unrecognized($op),
        };
    }

    /**
     * @param  list<mixed>  $values
     *
     * @throws JsonLogicError
     */
    private function registered(string $op, mixed $data, array $values): mixed
    {
        try {
            return ($this->operations[$op])($data, $values);
        } catch (JsonLogicError $failure) {
            throw $failure;
        } catch (Throwable $failure) {
            throw new JsonLogicError("Operation [{$op}] failed: {$failure->getMessage()}", 0, $failure);
        }
    }

    /**
     * The reference walks a dotted name through its operation table: a first segment it does not hold is an
     * unrecognized operation; one it holds is a function, and indexing a function is a TypeError (GENERAL).
     *
     * @throws UnknownOperator
     * @throws JsonLogicError
     */
    private function unrecognized(string $op): never
    {
        $head = strstr($op, '.', true);
        if ($head !== false && (isset($this->operations[$head]) || in_array($head, self::BUILTINS, true))) {
            throw new JsonLogicError("Operation [{$head}] has no member operations, so [{$op}] cannot run.");
        }

        throw new UnknownOperator($op);
    }

    /**
     * @param  list<mixed>  $args
     *
     * @throws UnknownOperator
     * @throws JsonLogicError
     */
    private function conditional(array $args, mixed $data, int $depth): mixed
    {
        $count = count($args);
        $index = 0;

        while ($index < $count - 1) {
            if (self::truthy($this->evaluate($args[$index], $data, $depth))) {
                return $this->evaluate($args[$index + 1], $data, $depth);
            }
            $index += 2;
        }

        return $index < $count ? $this->evaluate($args[$index], $data, $depth) : null;
    }

    /**
     * A single-member object is an operation; everything else is data and evaluates to itself.
     *
     * @return array{0: string, 1: list<mixed>}|null
     */
    private static function operation(mixed $logic): ?array
    {
        if (! Json::isObject($logic)) {
            return null;
        }

        $members = Json::members($logic);
        if (count($members) !== 1) {
            return null;
        }

        $name = array_key_first($members);
        $args = $members[$name];

        return [(string) $name, Json::isList($args) ? $args : [$args]];
    }

    private static function kind(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_string($value) => 'string',
            Json::isList($value) => 'list',
            default => 'object',
        };
    }

    /**
     * Python's numbers: int, float and bool (a bool IS an int there).
     *
     * @phpstan-assert-if-true bool|int|float $value
     */
    private static function isNumeric(mixed $value): bool
    {
        return is_int($value) || is_float($value) || is_bool($value);
    }

    /** The reference's to_number(), which hands a boolean back unchanged (it is already a Python number). */
    private static function numeric(mixed $value): int|float|bool
    {
        if (self::isNumeric($value)) {
            return $value;
        }
        if ($value === null) {
            return 0;
        }
        if (Json::isList($value)) {
            return match (count($value)) {
                0 => 0,
                1 => self::numeric($value[0] ?? null),
                default => NAN,
            };
        }
        if (is_string($value)) {
            return self::parseFloat($value);
        }

        return NAN;
    }

    /**
     * Python's ordering of two numbers, exact across int and float: -1, 0 or 1, or null when either is NaN.
     */
    private static function compareNumbers(int|float $a, int|float $b): ?int
    {
        if (is_int($a)) {
            return is_int($b) ? $a <=> $b : self::compareIntWithFloat($a, $b);
        }
        if (is_int($b)) {
            $order = self::compareIntWithFloat($b, $a);

            return $order === null ? null : -$order;
        }

        return is_nan($a) || is_nan($b) ? null : $a <=> $b;
    }

    private static function compareIntWithFloat(int $int, float $float): ?int
    {
        if (is_nan($float)) {
            return null;
        }
        if ($float >= 9223372036854775808.0) {
            return -1;
        }
        if ($float < -9223372036854775808.0) {
            return 1;
        }

        // Within the int range a float's floor is an exact int, so the comparison loses nothing.
        $floor = floor($float);
        $whole = (int) $floor;
        if ($int !== $whole) {
            return $int <=> $whole;
        }

        return $floor === $float ? 0 : -1;
    }

    /**
     * `<`, `<=`, `>`, `>=` with an optional third argument (a between test); $strict is the order that must hold,
     * $loose the other order also accepted (equal for `<=`/`>=`).
     */
    private static function chain(mixed $a, mixed $b, mixed $c, int $strict, int $loose): bool
    {
        $order = self::order($a, $b);
        if ($order !== $strict && $order !== $loose) {
            return false;
        }
        if ($c === null) {
            return true;
        }
        $order = self::order($b, $c);

        return $order === $strict || $order === $loose;
    }

    /** The reference's less_than() family: numbers if either side is one, else text if either side is text. */
    private static function order(mixed $a, mixed $b): ?int
    {
        if (self::isNumeric($a) || self::isNumeric($b) || (! is_string($a) && ! is_string($b))) {
            return self::compareNumbers(self::toNumber($a), self::toNumber($b));
        }

        return strcmp(self::toStr($a), self::toStr($b)) <=> 0;
    }

    /**
     * @param  list<mixed>  $values
     */
    private static function sum(array $values): int|float
    {
        $sum = 0;
        foreach ($values as $value) {
            $sum += self::toNumber($value);
        }

        return $sum;
    }

    /**
     * @param  list<mixed>  $values
     */
    private static function product(array $values): int|float
    {
        $product = 1;
        foreach ($values as $value) {
            $product *= self::toNumber($value);
        }

        return $product;
    }

    /**
     * @throws JsonLogicError
     */
    private static function divide(mixed $a, mixed $b): float
    {
        $divisor = self::toNumber($b);
        if ($divisor === 0 || $divisor === 0.0) {
            throw new JsonLogicError('Division by zero.');
        }

        return (float) self::toNumber($a) / (float) $divisor;
    }

    /**
     * @throws JsonLogicError
     */
    private static function modulo(mixed $a, mixed $b): int|float
    {
        $x = self::toNumber($a);
        $y = self::toNumber($b);
        if ($y === 0 || $y === 0.0) {
            throw new JsonLogicError('Modulo by zero.');
        }

        if (is_int($x) && is_int($y)) {
            $remainder = $x % $y;

            return $remainder !== 0 && ($remainder < 0) !== ($y < 0) ? $remainder + $y : $remainder;
        }

        $remainder = fmod((float) $x, (float) $y);
        if ($remainder === 0.0) {
            return $y < 0 ? -0.0 : 0.0;
        }

        return ($remainder < 0) !== ($y < 0) ? $remainder + $y : $remainder;
    }

    /**
     * Python's min()/max() over to_number(): the first of equals wins, NaN never replaces, a boolean stays one.
     *
     * @param  list<mixed>  $values
     */
    private static function extreme(array $values, int $better): int|float|bool
    {
        if ($values === []) {
            return NAN;
        }

        $best = self::numeric($values[0]);
        foreach (array_slice($values, 1) as $value) {
            $number = self::numeric($value);
            if (self::compareNumbers(self::toNumber($number), self::toNumber($best)) === $better) {
                $best = $number;
            }
        }

        return $best;
    }

    private static function in(mixed $needle, mixed $haystack): bool
    {
        if (Json::isList($haystack)) {
            foreach ($haystack as $item) {
                if (self::strictEquals($needle, $item)) {
                    return true;
                }
            }

            return false;
        }

        return is_string($haystack) && str_contains($haystack, self::toStr($needle));
    }

    /**
     * @param  list<mixed>  $values
     */
    private static function concatenate(array $values): string
    {
        $text = '';
        foreach ($values as $value) {
            $text .= self::toStr($value);
        }

        return $text;
    }

    /**
     * The reference's op_substr over code points: a negative index counts from the end, a negative length stops
     * that far from the end, NaN reads as 0 (index) or as an empty slice (length).
     *
     * @throws JsonLogicError
     */
    private static function substr(mixed $string, mixed $index, mixed $length): string
    {
        $text = self::toStr($string);
        $size = mb_strlen($text, 'UTF-8');
        $start = self::toNumber($index);

        if (is_float($start) && is_nan($start)) {
            $from = 0;
        } elseif ($start < 0) {
            $back = -$start;
            $from = $back >= $size ? 0 : $size - self::truncate($back);
        } else {
            $from = min(self::truncate($start), $size);
        }

        if ($length === null) {
            $to = $size;
        } else {
            $count = self::toNumber($length);
            if (is_float($count) && is_nan($count)) {
                $to = $from;
            } else {
                $count = self::truncate($count);
                if ($count < 0) {
                    $to = max($size + $count, $from);
                } else {
                    $to = $count >= $size - $from ? $size : $from + $count;
                }
            }
        }

        return $to > $from ? mb_substr($text, $from, $to - $from, 'UTF-8') : '';
    }

    /**
     * Python's int() of a number: toward zero, exact for any finite value (clamped here to the int range, which
     * every caller then clamps to a string length), an OverflowError (GENERAL) for an infinity.
     *
     * @throws JsonLogicError
     */
    private static function truncate(int|float $number): int
    {
        if (is_int($number)) {
            return $number;
        }
        if (is_infinite($number)) {
            throw new JsonLogicError('Cannot convert an infinite number to an integer.');
        }
        if ($number >= 9223372036854775808.0) {
            return PHP_INT_MAX;
        }
        if ($number < -9223372036854775808.0) {
            return PHP_INT_MIN;
        }

        return (int) $number;
    }

    /**
     * @param  list<mixed>  $values
     * @return list<mixed>
     */
    private static function merge(array $values): array
    {
        $merged = [];
        foreach ($values as $value) {
            if (Json::isList($value)) {
                array_push($merged, ...$value);
            } else {
                $merged[] = $value;
            }
        }

        return $merged;
    }

    /**
     * The reference's op_var: a number indexes a list or a string (an object holds only text keys, so a number
     * never finds a member); text is a dot path whose list and string steps are canonical indexes or `length`.
     *
     * @throws JsonLogicError
     */
    private static function variable(mixed $data, mixed $key, mixed $default): mixed
    {
        if ($key === null || $key === '') {
            return $data;
        }

        if (self::isNumeric($key)) {
            if (! Json::isList($data) && ! is_string($data)) {
                return $default;
            }
            $index = self::exactIndex($key);
            if ($index === null || $index < 0 || $index >= self::length($data)) {
                return $default;
            }

            return is_string($data) ? mb_substr($data, $index, 1, 'UTF-8') : $data[$index];
        }

        $value = $data;
        foreach (explode('.', self::toStr($key)) as $property) {
            if (Json::isList($value) || is_string($value)) {
                if ($property === 'length') {
                    return self::length($value);
                }
                if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $property) !== 1) {
                    return $default;
                }
                $position = (int) $property;
                if ($position >= self::length($value)) {
                    return $default;
                }
                $value = is_string($value) ? mb_substr($value, $position, 1, 'UTF-8') : $value[$position];
            } elseif (Json::isObject($value)) {
                $members = Json::members($value);
                $value = array_key_exists($property, $members) ? $members[$property] : null;
            } else {
                return $default;
            }
        }

        return $value ?? $default;
    }

    /**
     * A numeric `var` key as a list index: null when the reference's int(key) differs from the key (a fraction,
     * NaN, a float beyond the int range), an OverflowError (GENERAL) for an infinity.
     *
     * @throws JsonLogicError
     */
    private static function exactIndex(int|float|bool $key): ?int
    {
        if (! is_float($key)) {
            return (int) $key;
        }
        if (is_nan($key)) {
            return null;
        }
        if (is_infinite($key)) {
            throw new JsonLogicError('Cannot convert an infinite number to an integer.');
        }
        if ($key >= 9223372036854775808.0 || $key < -9223372036854775808.0) {
            return null;
        }

        $index = (int) $key;

        return (float) $index === $key ? $index : null;
    }

    /**
     * @param  list<mixed>|string  $value
     */
    private static function length(array|string $value): int
    {
        return is_string($value) ? mb_strlen($value, 'UTF-8') : count($value);
    }

    /**
     * @param  list<mixed>  $values
     * @return list<mixed>
     *
     * @throws JsonLogicError
     */
    private static function missing(mixed $data, array $values): array
    {
        $keys = $values !== [] && Json::isList($values[0]) ? $values[0] : $values;

        $missing = [];
        foreach ($keys as $key) {
            $value = self::variable($data, $key, null);
            if ($value === null || $value === '') {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * @return list<mixed>
     *
     * @throws JsonLogicError
     */
    private static function missingSome(mixed $data, mixed $need, mixed $keys): array
    {
        if (! Json::isList($keys)) {
            return [];
        }

        $missing = self::missing($data, [$keys]);
        $order = self::compareNumbers(count($keys) - count($missing), self::toNumber($need));

        return $order !== null && $order >= 0 ? [] : $missing;
    }

    /**
     * The reference's to_number() of text: NaN for "inf"/"+inf"/"-inf" once Python's strip() and lower() ran,
     * else Python's float() — which reads Unicode decimal digits as digits and strips non-ASCII spaces — or NaN
     * where float() raises.
     */
    private static function parseFloat(string $value): float
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            return NAN;
        }

        if (in_array(strtolower(self::strip($value, self::STRIP_SPACE)), ['inf', '+inf', '-inf'], true)) {
            return NAN;
        }

        $ascii = preg_replace_callback('/[^\x00-\x7F]/u', self::asciiDigit(...), self::strip($value, self::FLOAT_SPACE));
        if ($ascii === null) {
            return NAN;
        }

        $text = strtolower($ascii);
        if (str_contains($text, '_')) {
            // float() accepts an underscore only between two digits, then reads the text without it.
            if (preg_match('/(?<![0-9])_|_(?![0-9])/', $text) === 1) {
                return NAN;
            }
            $text = str_replace('_', '', $text);
        }
        if (preg_match('/^([+-]?)(infinity|inf|nan)$/D', $text, $special) === 1) {
            return $special[2] === 'nan' ? NAN : ($special[1] === '-' ? -INF : INF);
        }

        return preg_match(self::FLOAT_SYNTAX, $text) === 1 ? (float) $text : NAN;
    }

    /**
     * Python's strip() of one set of characters, in one pass from each end (a regular expression for the trailing
     * run would retry from every position of an inner run of spaces: quadratic on hostile text). The text is
     * valid UTF-8, so a character starts at any byte that is not a continuation byte.
     *
     * @param  list<string>  $spaces
     */
    private static function strip(string $value, array $spaces): string
    {
        $start = 0;
        $end = strlen($value);
        while ($start < $end) {
            $lead = ord($value[$start]);
            $width = $lead < 0x80 ? 1 : ($lead < 0xE0 ? 2 : ($lead < 0xF0 ? 3 : 4));
            if (! in_array(substr($value, $start, $width), $spaces, true)) {
                break;
            }
            $start += $width;
        }
        while ($end > $start) {
            $from = $end - 1;
            while ($from > $start && (ord($value[$from]) & 0xC0) === 0x80) {
                $from--;
            }
            if (! in_array(substr($value, $from, $end - $from), $spaces, true)) {
                break;
            }
            $end = $from;
        }

        return substr($value, $start, $end - $start);
    }

    /**
     * float()'s view of one non-ASCII character: a Unicode decimal digit is its ASCII digit, anything else makes
     * the text unparsable. Unicode encodes every decimal digit in runs of ten from 0, so a digit's value is its
     * distance from the start of its runs modulo ten; no run starts right after a surrogate, so the walk back
     * never asks mb_chr() for one.
     *
     * @param  array<array-key, string>  $match
     */
    private static function asciiDigit(array $match): string
    {
        $character = $match[0] ?? '';
        if (isset(self::$digits[$character])) {
            return self::$digits[$character];
        }
        if (preg_match('/^\p{Nd}$/u', $character) !== 1) {
            return '?';
        }

        $code = mb_ord($character, 'UTF-8');
        $distance = 0;
        while ($code - $distance > 0 && preg_match('/^\p{Nd}$/u', mb_chr($code - $distance - 1, 'UTF-8')) === 1) {
            $distance++;
        }

        return self::$digits[$character] = (string) ($distance % 10);
    }

    /** Python's `'%.15g' % value`. */
    private static function formatG15(float $value): string
    {
        if (is_nan($value)) {
            return 'nan';
        }
        if (is_infinite($value)) {
            return $value > 0 ? 'inf' : '-inf';
        }
        if ($value === 0.0) {
            return fdiv(1.0, $value) < 0 ? '-0' : '0';
        }

        [$mantissa, $exponent] = explode('e', sprintf('%.14e', $value));
        $power = (int) $exponent;

        if ($power < -4 || $power >= 15) {
            $digits = str_contains($mantissa, '.') ? rtrim(rtrim($mantissa, '0'), '.') : $mantissa;

            return $digits.'e'.($power < 0 ? '-' : '+').str_pad((string) abs($power), 2, '0', STR_PAD_LEFT);
        }

        $fixed = sprintf('%.'.(14 - $power).'F', $value);

        return str_contains($fixed, '.') ? rtrim(rtrim($fixed, '0'), '.') : $fixed;
    }
}
