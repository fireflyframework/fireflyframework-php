<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Evaluation\PythonText;

/*
 | Every expected text is what Python 3.14's str() writes for the same JSON value: the reference evaluator names
 | the variant a targeting result selects with it, and sem_ver reads a number through it.
 */
it('writes a JSON value as Python str() does', function (mixed $value, string $expected): void {
    expect(PythonText::str($value))->toBe($expected);
})->with([
    'text is itself' => ["a'b", "a'b"],
    'an integer' => [-42, '-42'],
    'a whole float' => [2.0, '2.0'],
    'negative zero' => [-0.0, '-0.0'],
    'seventeen digits' => [0.1 + 0.2, '0.30000000000000004'],
    'an exponent' => [1e16, '1e+16'],
    'a small exponent' => [1e-5, '1e-05'],
    'the smallest fixed float' => [0.0001, '0.0001'],
    'the largest float' => [1.7976931348623157e308, '1.7976931348623157e+308'],
    'the smallest subnormal' => [5e-324, '5e-324'],
    'infinity' => [INF, 'inf'],
    'true' => [true, 'True'],
    'null' => [null, 'None'],
    'an empty list' => [[], '[]'],
    'an empty object' => [new stdClass, '{}'],
    'a list' => [[1, 'a', 2.5, false, null, [], new stdClass], "[1, 'a', 2.5, False, None, [], {}]"],
    'nested objects, numeric-looking names as text' => [['1' => ['k' => [true]], '' => 'x'], "{'1': {'k': [True]}, '': 'x'}"],
    'a single quote picks double quotes' => [["it's"], '["it\'s"]'],
    'both quotes escape the single one' => [["a'b\"c"], '[\'a\\\'b"c\']'],
    'a double quote alone stays' => [['say "hi"'], '[\'say "hi"\']'],
    'backslash and control characters' => [["\\ \t\n\r\x00\x1f\x7f"], '[\'\\\\ \t\n\r\x00\x1f\x7f\']'],
    'latin-1 controls and the no-break space' => [["\u{80}\u{9f}\u{a0}\u{ad}"], '[\'\x80\x9f\xa0\xad\']'],
    'printable characters as they are' => [['é☃東京🚀'], "['é☃東京🚀']"],
    'zero-width, separators and the byte-order mark' => [["\u{200b}\u{2028}\u{2029}\u{3000}\u{feff}"], '[\'\\u200b\\u2028\\u2029\\u3000\\ufeff\']'],
    'private-use and unassigned characters' => [["\u{e000}\u{f0000}\u{10ffff}\u{378}"], '[\'\\ue000\\U000f0000\\U0010ffff\\u0378\']'],
]);

it('has no text for a value Python has no counterpart for', function (mixed $value): void {
    expect(PythonText::str($value))->toBeNull();
})->with([
    'a PHP object' => [new ArrayObject],
    'invalid UTF-8 inside a list' => [["\xff"]],
    'invalid UTF-8 as a name' => [["\xff" => 1]],
]);

it('writes a float as repr() does whatever PHP precision says', function (): void {
    $precision = ini_get('precision');
    ini_set('precision', '3');

    try {
        expect(PythonText::float(0.1 + 0.2))->toBe('0.30000000000000004');
    } finally {
        ini_set('precision', (string) $precision);
    }
});
