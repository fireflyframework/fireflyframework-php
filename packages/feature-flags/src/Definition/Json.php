<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Definition;

use JsonException;
use stdClass;

/**
 * JSON as the flag contract needs it in PHP.
 *
 * A flagd document crosses frameworks through the store table and the sync endpoint, and PHP's associative
 * decoding loses the one distinction PyFly reads: `{}` and `[]` both become `[]`, so an empty object variant
 * written back would reach Python as a list. So objects decode to string-keyed arrays EXCEPT those whose array
 * form would read as a list — `{}` and `{"0": …}` — which stay stdClass. A PHP `[]` is therefore always a JSON
 * array, and encode() gives a decoded value back as the same JSON value: member order, `{}` versus `[]` and
 * integer versus float survive, though not always the same text (`1E2` comes back as `100.0`). Two limits are
 * PHP's own: a number beyond the float range (`1E400`) decodes to INF, which encode() refuses with a
 * JsonException, and an integer beyond 64 bits decodes to a float.
 *
 * A member name that looks like an integer (`"2024"`, `"1"`) is an int array key in PHP. It is still text in
 * JSON: callers that need the name read it with `(string) $key`.
 */
final class Json
{
    public const int ENCODE_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

    /** @throws JsonException */
    public static function decode(string $json): mixed
    {
        try {
            return self::normalize(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
        } catch (JsonException $failure) {
            if ($failure->getCode() !== JSON_ERROR_INVALID_PROPERTY_NAME) {
                throw $failure;
            }
        }

        // Native validation keeps syntax, depth and number semantics. PHP objects alone cannot hold leading
        // NUL names. Prefix every member token (not values), decode natively, then remove exactly one prefix.
        json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $prefixed = preg_replace_callback('/"[^"\\\\]*+(?:\\\\.[^"\\\\]*+)*+"(\s*:)?/s',
            static fn (array $token): string => isset($token[1]) ? '"_'.substr($token[0], 1) : $token[0], $json);
        if ($prefixed === null) {
            throw new JsonException('Cannot tokenize validated JSON: '.preg_last_error_msg());
        }

        return self::normalizeValue(json_decode($prefixed, false, 512, JSON_THROW_ON_ERROR), true);
    }

    /**
     * A stdClass tree (json_decode() without assoc, Yaml::PARSE_OBJECT_FOR_MAP) in the faithful form above.
     */
    public static function normalize(mixed $value): mixed
    {
        return self::normalizeValue($value, false);
    }

    private static function normalizeValue(mixed $value, bool $prefixed): mixed
    {
        if ($value instanceof stdClass) {
            $members = [];
            foreach (get_object_vars($value) as $key => $member) {
                $members[$prefixed ? substr((string) $key, 1) : $key] = self::normalizeValue($member, $prefixed);
            }

            return $members === [] || array_is_list($members) ? (object) $members : $members;
        }

        if (is_array($value)) {
            return array_map(static fn (mixed $member): mixed => self::normalizeValue($member, $prefixed), $value);
        }

        return $value;
    }

    /** @throws JsonException on INF or NAN, malformed UTF-8, or nesting deeper than 512 levels */
    public static function encode(mixed $value): string
    {
        return json_encode($value, self::ENCODE_FLAGS | JSON_THROW_ON_ERROR);
    }

    /**
     * The encoding with object members sorted by name, recursively; `[]` and `{}` stay distinct.
     *
     * @throws JsonException as encode()
     */
    public static function canonical(mixed $value): string
    {
        return self::encode(self::sorted($value));
    }

    /**
     * A stdClass, or a non-empty array that is not a list.
     */
    public static function isObject(mixed $value): bool
    {
        return $value instanceof stdClass || (is_array($value) && $value !== [] && ! array_is_list($value));
    }

    /**
     * An array that is a list, so `[]` is a list.
     *
     * @phpstan-assert-if-true list<mixed> $value
     */
    public static function isList(mixed $value): bool
    {
        return is_array($value) && array_is_list($value);
    }

    /**
     * An object's members; anything else (a list, `[]`, a scalar, null) has none.
     *
     * @return array<array-key, mixed>
     */
    public static function members(mixed $value): array
    {
        if ($value instanceof stdClass) {
            return get_object_vars($value);
        }

        return is_array($value) && ! array_is_list($value) ? $value : [];
    }

    /**
     * Deep stdClass → array, for the OpenFeature SDK and PHP callers (which cannot tell `{}` from `[]`).
     */
    public static function toPhp(mixed $value): mixed
    {
        if ($value instanceof stdClass) {
            $value = get_object_vars($value);
        }

        return is_array($value) ? array_map(self::toPhp(...), $value) : $value;
    }

    /**
     * A value for an OBJECT position: `[]` or a list-like array becomes a stdClass so it encodes as `{…}`.
     *
     * @param  array<array-key, mixed>|stdClass  $members
     * @return array<array-key, mixed>|stdClass
     */
    public static function object(array|stdClass $members): array|stdClass
    {
        if ($members instanceof stdClass) {
            return $members;
        }

        return $members === [] || array_is_list($members) ? (object) $members : $members;
    }

    private static function sorted(mixed $value): mixed
    {
        if (self::isObject($value)) {
            $members = [];
            foreach (self::members($value) as $key => $member) {
                $members[(string) $key] = self::sorted($member);
            }
            ksort($members, SORT_STRING);

            return self::object($members);
        }

        return is_array($value) ? array_map(self::sorted(...), $value) : $value;
    }
}
