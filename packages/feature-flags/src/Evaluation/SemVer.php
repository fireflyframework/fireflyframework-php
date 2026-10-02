<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Evaluation;

/**
 * flagd's `sem_ver` [version, operator, version] as the reference evaluator runs it (openfeature-flagd-core 1.0.0
 * on python-semver 3.1.0): str() of each side — a float as Python writes it (PythonText::float()), so 0.1 + 0.2
 * is "0.30000000000000004" and 1e16 is "1e+16", never PHP's 14-digit string cast — a leading v/V dropped, a
 * partial version padded to three parts ("1" → "1.0.0", "1.2-rc.1" → "1.2.0-rc.1"), then a STRICT SemVer 2.0.0
 * parse: "2.0.0.0", "01.0.0" and "1.0.0\n" do not parse, and neither do a boolean, null, a list or an object.
 * Precedence ignores build metadata and compares numbers exactly at any length. `^` compares majors only and
 * `~` majors and minors (flagd's meaning, not npm's). An unparsable version, an unknown operator or a wrong
 * argument count → null, so the default variant applies.
 *
 * Python's own int() limit is kept: a major, minor or patch longer than 4300 digits does not parse (null), and
 * comparing two prereleases when either holds a numeric identifier that long raises in the reference, outside
 * its try, so this throws a JsonLogicError (GENERAL). The parse is linear, where python-semver's pattern would
 * exhaust PCRE's backtracking stack on a long prerelease that Python reads.
 *
 * Two limits are PHP's: an integer beyond 64 bits decodes to a float, so a 20-digit version number reads as
 * "1e+20" and does not parse where Python reads the integer; and a list nested past Python's recursion limit
 * is simply not a version here, where the reference's str() raises RecursionError (GENERAL).
 */
final class SemVer
{
    /** Python's default sys.get_int_max_str_digits(): int() of a longer digit string raises ValueError. */
    private const int PYTHON_INT_MAX_STR_DIGITS = 4300;

    /** major.minor.patch, then the prerelease and build texts, whose identifiers parse() checks one by one. */
    private const string SHAPE = '/^(0|[1-9][0-9]*+)\.(0|[1-9][0-9]*+)\.(0|[1-9][0-9]*+)(?:-([0-9A-Za-z.-]++))?(?:\+([0-9A-Za-z.-]++))?$/D';

    /**
     * @param  list<mixed>  $args
     *
     * @throws JsonLogicError where the reference raises
     */
    public static function evaluate(mixed $data, array $args): ?bool
    {
        if (count($args) !== 3) {
            return null;
        }

        [$left, $operator, $right] = $args;
        $a = self::parse($left);
        $b = self::parse($right);
        if ($a === null || $b === null) {
            return null;
        }

        return match ($operator) {
            '=' => self::compare($a, $b) === 0,
            '!=' => self::compare($a, $b) !== 0,
            '<' => self::compare($a, $b) < 0,
            '<=' => self::compare($a, $b) <= 0,
            '>' => self::compare($a, $b) > 0,
            '>=' => self::compare($a, $b) >= 0,
            '^' => $a['major'] === $b['major'],
            '~' => $a['major'] === $b['major'] && $a['minor'] === $b['minor'],
            default => null,
        };
    }

    /**
     * @return array{major: string, minor: string, patch: string, prerelease: string|null}|null
     */
    public static function parse(mixed $value): ?array
    {
        $version = match (true) {
            is_string($value) => $value,
            is_int($value) => (string) $value,
            is_float($value) => PythonText::float($value),
            default => null,
        };
        if ($version === null) {
            return null;
        }

        if (str_starts_with($version, 'v') || str_starts_with($version, 'V')) {
            $version = substr($version, 1);
        }

        $numeric = explode('+', explode('-', $version, 2)[0], 2)[0];
        $dots = substr_count($numeric, '.');
        if ($dots < 2) {
            $version = $numeric.str_repeat('.0', 2 - $dots).substr($version, strlen($numeric));
        }

        if (preg_match(self::SHAPE, $version, $parts) !== 1) {
            return null;
        }

        [, $major, $minor, $patch] = $parts;
        $prerelease = ($parts[4] ?? '') !== '' ? $parts[4] : null;
        $build = $parts[5] ?? null;

        foreach ([$major, $minor, $patch] as $number) {
            if (strlen($number) > self::PYTHON_INT_MAX_STR_DIGITS) {
                return null;
            }
        }

        if ($prerelease !== null) {
            foreach (explode('.', $prerelease) as $identifier) {
                // A numeric identifier has no leading zero; an alphanumeric one may (`00a`).
                if ($identifier === '' || (ctype_digit($identifier) && $identifier !== '0' && $identifier[0] === '0')) {
                    return null;
                }
            }
        }

        if ($build !== null && in_array('', explode('.', $build), true)) {
            return null;
        }

        return ['major' => $major, 'minor' => $minor, 'patch' => $patch, 'prerelease' => $prerelease];
    }

    /**
     * python-semver's Version.compare(): the cores as integers, then the prereleases (a release above any of
     * its prereleases).
     *
     * @param  array{major: string, minor: string, patch: string, prerelease: string|null}  $a
     * @param  array{major: string, minor: string, patch: string, prerelease: string|null}  $b
     *
     * @throws JsonLogicError
     */
    private static function compare(array $a, array $b): int
    {
        foreach (['major', 'minor', 'patch'] as $part) {
            $order = self::numeric($a[$part], $b[$part]);
            if ($order !== 0) {
                return $order;
            }
        }

        $natural = self::natural($a['prerelease'], $b['prerelease']);

        return match (true) {
            $natural === 0 => 0,
            $a['prerelease'] === null => 1,
            $b['prerelease'] === null => -1,
            default => $natural,
        };
    }

    /**
     * python-semver's _nat_cmp(): dot-separated identifiers, digits as numbers and below text, then the longer
     * list above. Python converts every numeric identifier of both sides before it compares any.
     *
     * @throws JsonLogicError
     */
    private static function natural(?string $a, ?string $b): int
    {
        $left = explode('.', $a ?? '');
        $right = explode('.', $b ?? '');

        foreach ([...$left, ...$right] as $identifier) {
            if (strlen($identifier) > self::PYTHON_INT_MAX_STR_DIGITS && ctype_digit($identifier)) {
                throw new JsonLogicError('sem_ver: a numeric prerelease identifier exceeds the limit ('.self::PYTHON_INT_MAX_STR_DIGITS.' digits) for integer string conversion.');
            }
        }

        $shared = min(count($left), count($right));
        for ($i = 0; $i < $shared; $i++) {
            $x = $left[$i];
            $y = $right[$i];
            $order = match (true) {
                ctype_digit($x) && ctype_digit($y) => self::numeric($x, $y),
                ctype_digit($x) => -1,
                ctype_digit($y) => 1,
                default => strcmp($x, $y) <=> 0,
            };
            if ($order !== 0) {
                return $order;
            }
        }

        return count($left) <=> count($right);
    }

    /** Non-negative integers of any length without leading zeros, compared exactly. */
    private static function numeric(string $a, string $b): int
    {
        return strlen($a) <=> strlen($b) ?: strcmp($a, $b) <=> 0;
    }
}
