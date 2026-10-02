<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Evaluation;

use Firefly\FeatureFlags\Definition\Json;
use stdClass;

/**
 * The text Python's str() gives a JSON value, where the reference evaluator (openfeature-flagd-core 1.0.0) turns
 * one into text: `sem_ver` reads a number as the version str() writes, and the core names the variant a targeting
 * result selects with str() — a `fractional` bucket may be named by a number, `[1, 50]` selecting the variant "1".
 *
 * str() of text is the text; of an integer, its digits; of a float, the shortest digits that read back as the same
 * float (`1.0`, `0.30000000000000004`, `1e+16`, `inf`, `nan`), whatever PHP's `precision` ini says; of a boolean
 * `True`/`False` and of null `None`; of a list or an object, Python's repr of the list or the dict
 * (`[1, 'a', None]`, `{'k': True}`), its text quoted and escaped as repr() does. A PHP object other than a
 * stdClass, and invalid UTF-8 inside a list or an object, have no Python counterpart: null.
 *
 * An object that contains itself is written as repr() writes a dict that does: `{...}` where it recurs. A PHP array
 * that holds a reference to itself, and anything nested deeper than MAX_DEPTH, has no text here (null): PHP arrays
 * have no identity to recognise the recurrence by.
 *
 * Python decides which characters repr() escapes from its Unicode database (16.0 in Python 3.14); this reads the
 * same categories through PCRE's, so a character assigned in one version and not the other may differ.
 */
final class PythonText
{
    /**
     * Characters repr() never writes as they are: Unicode "Other" and "Separator" (the ASCII space aside, which
     * the caller never asks about).
     */
    private const string NOT_PRINTABLE = '/^[\p{Cc}\p{Cf}\p{Cs}\p{Co}\p{Cn}\p{Zl}\p{Zp}\p{Zs}]$/u';

    /** The most lists and objects one value may nest; deeper (or a PHP array holding itself) has no text. */
    public const int MAX_DEPTH = 1000;

    /** Python's str() of a JSON value; null when it has no Python counterpart. */
    public static function str(mixed $value): ?string
    {
        return is_string($value) ? $value : self::repr($value);
    }

    /** Python's repr() of a JSON value (str() of a list or a dict writes its items this way); null as str(). */
    public static function repr(mixed $value): ?string
    {
        return self::written($value, [], 0);
    }

    /**
     * @param  array<int, true>  $open  the ids of the objects being written on this path
     */
    private static function written(mixed $value, array $open, int $depth): ?string
    {
        if (is_string($value)) {
            return self::quote($value);
        }
        if (is_bool($value)) {
            return $value ? 'True' : 'False';
        }
        if ($value === null) {
            return 'None';
        }
        if (is_int($value)) {
            return (string) $value;
        }
        if (is_float($value)) {
            return self::float($value);
        }

        if ((is_array($value) || $value instanceof stdClass) && $depth >= self::MAX_DEPTH) {
            return null;
        }

        if (Json::isList($value)) {
            $items = [];
            foreach ($value as $item) {
                $text = self::written($item, $open, $depth + 1);
                if ($text === null) {
                    return null;
                }
                $items[] = $text;
            }

            return '['.implode(', ', $items).']';
        }

        if (is_array($value) || $value instanceof stdClass) {
            if ($value instanceof stdClass) {
                if (isset($open[spl_object_id($value)])) {
                    return '{...}';
                }
                $open[spl_object_id($value)] = true;
            }
            $items = [];
            foreach (Json::members($value) as $name => $member) {
                $key = self::quote((string) $name);
                $text = self::written($member, $open, $depth + 1);
                if ($key === null || $text === null) {
                    return null;
                }
                $items[] = $key.': '.$text;
            }

            return '{'.implode(', ', $items).'}';
        }

        return null;
    }

    /**
     * Python's repr() of a float (what str() gives), independent of PHP's `precision` ini: the shortest digits
     * that read back as the same float, fixed notation for 1e-4 <= |x| < 1e16 (with ".0" when whole), an
     * exponent of at least two digits otherwise.
     */
    public static function float(float $value): string
    {
        if (is_nan($value)) {
            return 'nan';
        }
        if (is_infinite($value)) {
            return $value > 0 ? 'inf' : '-inf';
        }

        $sign = $value < 0 || ($value === 0.0 && fdiv(1.0, $value) < 0) ? '-' : '';
        $magnitude = abs($value);

        // Sixteen decimals in scientific notation is seventeen significant digits, which always read back.
        $precision = 0;
        while ($precision < 16 && (float) sprintf('%.'.$precision.'e', $magnitude) !== $magnitude) {
            $precision++;
        }
        [$mantissa, $exponent] = explode('e', sprintf('%.'.$precision.'e', $magnitude));
        $digits = rtrim(str_replace('.', '', $mantissa), '0');
        $digits = $digits === '' ? '0' : $digits;
        $point = (int) $exponent + 1;

        if ($point <= -4 || $point > 16) {
            $text = $digits[0].(strlen($digits) > 1 ? '.'.substr($digits, 1) : '');

            return sprintf('%s%se%s%02d', $sign, $text, $point - 1 < 0 ? '-' : '+', abs($point - 1));
        }

        return $sign.match (true) {
            $point <= 0 => '0.'.str_repeat('0', -$point).$digits,
            $point >= strlen($digits) => $digits.str_repeat('0', $point - strlen($digits)).'.0',
            default => substr($digits, 0, $point).'.'.substr($digits, $point),
        };
    }

    /**
     * repr() of text: single quotes unless the text holds a single quote and no double one; a backslash, the
     * quote, \t, \n and \r escaped; any other control or unprintable character as \xNN, \uNNNN or \UNNNNNNNN.
     */
    private static function quote(string $text): ?string
    {
        if (! mb_check_encoding($text, 'UTF-8')) {
            return null;
        }

        $quote = str_contains($text, "'") && ! str_contains($text, '"') ? '"' : "'";
        $escaped = preg_replace_callback(
            '/[\x00-\x1F\x7F\\\\\'"]|[^\x00-\x7F]/u',
            static function (array $match) use ($quote): string {
                $character = $match[0];

                return match (true) {
                    $character === '\\' => '\\\\',
                    $character === $quote => '\\'.$quote,
                    $character === '"', $character === "'" => $character,
                    $character === "\t" => '\t',
                    $character === "\n" => '\n',
                    $character === "\r" => '\r',
                    strlen($character) === 1 => sprintf('\x%02x', ord($character)),
                    preg_match(self::NOT_PRINTABLE, $character) !== 1 => $character,
                    default => self::escape(mb_ord($character, 'UTF-8')),
                };
            },
            $text,
        );

        return $escaped === null ? null : $quote.$escaped.$quote;
    }

    private static function escape(int|false $code): string
    {
        return match (true) {
            $code === false => '',
            $code <= 0xFF => sprintf('\x%02x', $code),
            $code <= 0xFFFF => sprintf('\u%04x', $code),
            default => sprintf('\U%08x', $code),
        };
    }
}
