<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Definition;

use stdClass;

/**
 * The contract's definition rules (spec §4.1, §4.2, CONTRACT.md "Rules every definition must satisfy"): shorthand
 * normalization, validation with the contract's fixed messages, and expiry. Every source runs parseDocument() before
 * the registry sees a document; a document that breaks a rule is rejected as a whole, and the first broken rule is
 * the one reported.
 *
 * A flag's rules run in the order PyFly checks them, so a definition breaking two rules reports the same one in both
 * frameworks: key, object, state, variants, variant types, defaultVariant, targeting, then metadata (values scalar,
 * keys not empty, kind, expires, owner, description), and last the finite-number rule over the whole definition
 * (fields flagd does not define included: they are kept as written and otherwise ignored, never an error).
 *
 * Two rules of the contract cannot be broken in PHP: `metadata keys must be strings` and `evaluator names must be
 * strings`. A PHP array key is text or an int, and an int key is a numeric-looking name (`"2024"`) that JSON and YAML
 * wrote as text (RF3), so it is accepted as that text; Symfony Yaml refuses a boolean, null or float mapping key when
 * it parses, before a document reaches this class.
 *
 * Validation never recurses: the finite-number rule walks a definition with a stack of its own, so a PHP-built
 * definition nested deeper than any decoder allows is still judged rather than exhausting the call stack. A JSON
 * document nested deeper than the decoder's 512 levels never gets here: decoding refuses it as a whole.
 */
final class FlagDefinitions
{
    public const string KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D';

    /** @var list<string> */
    public const array KINDS = ['release', 'experiment', 'ops', 'permission'];

    /**
     * Shorthand → flagd. Only config and test overrides accept shorthand; full definitions pass through
     * untouched and anything else is left for validate() to refuse.
     *
     * @param  array<array-key, mixed>  $flags
     * @return array<array-key, mixed>
     */
    public static function normalize(array $flags): array
    {
        $normalized = [];
        foreach ($flags as $key => $definition) {
            $normalized[$key] = match (true) {
                $definition === true => ['state' => 'ENABLED', 'variants' => ['on' => true, 'off' => false], 'defaultVariant' => 'on'],
                $definition === false => ['state' => 'ENABLED', 'variants' => ['on' => true, 'off' => false], 'defaultVariant' => 'off'],
                is_string($definition) => ['state' => 'ENABLED', 'variants' => Json::object([$definition => $definition]), 'defaultVariant' => $definition],
                default => $definition,
            };
        }

        return $normalized;
    }

    /**
     * Validate a flagd document (`flags`, `$evaluators`, `metadata`) as a whole and return it.
     *
     * Each section is empty when absent or null; a present section that is not an object is refused with the
     * section's name as the key. A single flag that breaks a rule is reported with its own key, an evaluator that is
     * not an object as `$evaluators.<name>`. A `$ref` naming no evaluator is not a load error: evaluating that flag
     * reports it.
     *
     * @throws InvalidFlagDefinition
     */
    public static function parseDocument(mixed $document): FlagDocument
    {
        if (! Json::isObject($document) && $document !== []) {
            throw new InvalidFlagDefinition('', 'flag document must be an object');
        }

        $members = Json::members($document);

        $definitions = [];
        foreach (self::section($members, 'flags', 'flags must be an object') as $key => $definition) {
            $key = (string) $key;
            self::validate($key, $definition);
            $definitions[$key] = FlagDefinition::fromJsonValue($key, $definition);
        }

        $evaluators = self::section($members, '$evaluators', '$evaluators must be an object');
        foreach ($evaluators as $name => $rule) {
            if (! Json::isObject($rule)) {
                throw new InvalidFlagDefinition('$evaluators.'.$name, 'an evaluator must be an object');
            }
            if (self::hasNonFiniteNumber($rule)) {
                throw new InvalidFlagDefinition('$evaluators', sprintf("numbers must be finite (evaluator '%s')", $name));
            }
        }

        $metadata = self::section($members, 'metadata', 'metadata must be an object');
        $reason = self::metadataReason($metadata);
        if ($reason !== null) {
            throw new InvalidFlagDefinition('metadata', $reason);
        }
        if (self::hasNonFiniteNumber($metadata)) {
            throw new InvalidFlagDefinition('metadata', 'numbers must be finite');
        }

        return new FlagDocument($definitions, $evaluators, $metadata);
    }

    /**
     * @throws InvalidFlagDefinition
     */
    public static function validate(string $key, mixed $definition): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidFlagDefinition($key, 'invalid flag key');
        }

        if (! Json::isObject($definition) && $definition !== []) {
            throw new InvalidFlagDefinition($key, 'flag definition must be an object');
        }

        $members = Json::members($definition);

        if (! in_array($members['state'] ?? null, ['ENABLED', 'DISABLED'], true)) {
            throw new InvalidFlagDefinition($key, 'state must be ENABLED or DISABLED');
        }

        $variants = Json::members($members['variants'] ?? null);
        if ($variants === []) {
            throw new InvalidFlagDefinition($key, 'variants must be a non-empty object');
        }

        $types = array_values(array_unique(array_map(self::jsonType(...), array_values($variants))));
        if (! in_array($types, [['boolean'], ['string'], ['number'], ['object']], true)) {
            throw new InvalidFlagDefinition($key, 'variants must share one type');
        }

        $default = $members['defaultVariant'] ?? null;
        if ($default !== null && (! is_string($default) || ! array_key_exists($default, $variants))) {
            throw new InvalidFlagDefinition($key, is_bool($default)
                ? 'defaultVariant is not a variant (a boolean names no variant: write the name as text)'
                : 'defaultVariant is not a variant');
        }

        $targeting = $members['targeting'] ?? null;
        if ($targeting !== null && ! Json::isObject($targeting) && $targeting !== []) {
            throw new InvalidFlagDefinition($key, 'targeting must be an object');
        }

        $metadata = $members['metadata'] ?? null;
        if ($metadata !== null) {
            self::validateMetadata($key, $metadata);
        }

        if (self::hasNonFiniteNumber($definition)) {
            throw new InvalidFlagDefinition($key, 'numbers must be finite');
        }
    }

    /**
     * Keys of the flags whose `expires` is before $today (YYYY-MM-DD, UTC), sorted.
     *
     * @param  array<array-key, FlagDefinition>  $flags
     * @return list<string>
     */
    public static function expiredKeys(array $flags, string $today): array
    {
        $expired = [];
        foreach ($flags as $flag) {
            if ($flag->isExpired($today)) {
                $expired[] = $flag->key;
            }
        }
        sort($expired, SORT_STRING);

        return $expired;
    }

    /**
     * The members of a document section: none when absent or null.
     *
     * @param  array<array-key, mixed>  $document
     * @return array<array-key, mixed>
     *
     * @throws InvalidFlagDefinition when the section is present and not an object
     */
    private static function section(array $document, string $name, string $reason): array
    {
        $section = $document[$name] ?? null;
        if ($section !== null && ! Json::isObject($section) && $section !== []) {
            throw new InvalidFlagDefinition($name, $reason);
        }

        return Json::members($section);
    }

    /**
     * A flag's `metadata`: a map of scalars with non-empty names, and the four reserved keys well-formed.
     *
     * @throws InvalidFlagDefinition
     */
    private static function validateMetadata(string $key, mixed $metadata): void
    {
        $reason = Json::isObject($metadata) || $metadata === [] ? self::metadataReason(Json::members($metadata)) : 'metadata values must be scalars';
        if ($reason !== null) {
            throw new InvalidFlagDefinition($key, $reason);
        }

        $reserved = Json::members($metadata);
        if (array_key_exists('kind', $reserved) && ! in_array($reserved['kind'], self::KINDS, true)) {
            throw new InvalidFlagDefinition($key, 'kind must be one of release, experiment, ops, permission');
        }
        if (array_key_exists('expires', $reserved) && ! self::isDate($reserved['expires'])) {
            throw new InvalidFlagDefinition($key, 'expires must be a YYYY-MM-DD date');
        }
        if (array_key_exists('owner', $reserved) && ! is_string($reserved['owner'])) {
            throw new InvalidFlagDefinition($key, 'owner must be a string');
        }
        if (array_key_exists('description', $reserved) && ! is_string($reserved['description'])) {
            throw new InvalidFlagDefinition($key, 'description must be a string');
        }
    }

    /**
     * Why a metadata map (a flag's or the document's) breaks the contract, or null: every value a string, number or
     * boolean, then no empty name.
     *
     * @param  array<array-key, mixed>  $metadata
     */
    private static function metadataReason(array $metadata): ?string
    {
        foreach ($metadata as $value) {
            if (! is_bool($value) && ! is_int($value) && ! is_float($value) && ! is_string($value)) {
                return 'metadata values must be scalars';
            }
        }

        return array_key_exists('', $metadata) ? 'metadata keys must not be empty' : null;
    }

    /**
     * Whether INF or NAN stands anywhere in $value. JSON has neither (YAML's `.inf`/`.nan` and PHP produce them), and
     * encoding one throws, so a definition holding one could be neither composed, stored nor served. Walks with a
     * stack of its own: no recursion, whatever the depth.
     */
    private static function hasNonFiniteNumber(mixed $value): bool
    {
        $pending = [$value];
        while ($pending !== []) {
            $item = array_pop($pending);
            if (is_float($item) && ! is_finite($item)) {
                return true;
            }
            if ($item instanceof stdClass) {
                $item = get_object_vars($item);
            }
            if (is_array($item)) {
                foreach ($item as $member) {
                    $pending[] = $member;
                }
            }
        }

        return false;
    }

    /** The JSON type a variant value has; null and anything JSON cannot hold share no type with a variant. */
    private static function jsonType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value), is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value), $value instanceof stdClass => 'object',
            default => get_debug_type($value),
        };
    }

    private static function isDate(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}
