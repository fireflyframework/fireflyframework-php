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
 * keys not empty, kind, expires, owner, description), and last the walk over the whole definition (fields flagd does
 * not define included: they are kept as written and otherwise ignored, never an error), which refuses nesting deeper
 * than MAX_DEPTH and then a number that is not finite. Evaluator rules and the document metadata take the same walk.
 *
 * Two rules of the contract cannot be broken in PHP: `metadata keys must be strings` and `evaluator names must be
 * strings`. A PHP array key is text or an int, and an int key is a numeric-looking name (`"2024"`) that JSON and YAML
 * wrote as text (RF3), so it is accepted as that text; Symfony Yaml refuses a boolean, null or float mapping key when
 * it parses, before a document reaches this class.
 *
 * An empty list (`[]`) where an object is expected — a flag's `targeting` or `metadata`, or a document section — counts
 * as an empty object (`{}`): PHP cannot tell the two apart in native configuration arrays. A non-empty list is still
 * an error, and so is `[]` as the document itself, as a flag definition or as an evaluator rule. A flag's `[]`
 * targeting or metadata is handed on as `{}`, so every reader sees the contract's "no targeting".
 *
 * Validation never recurses: the walk keeps a stack of its own and stops descending at MAX_DEPTH, so even a PHP-built
 * definition nested thousands of levels deep is refused cheaply. A definition that validates therefore always
 * encodes: a flag 256 levels deep sits 258 levels deep in its document, well inside json_encode()'s 512. A JSON
 * document nested deeper than the decoder's 512 levels never gets here: decoding refuses it as a whole.
 */
final class FlagDefinitions
{
    public const string KEY_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$/D';

    /** @var list<string> */
    public const array KINDS = ['release', 'experiment', 'ops', 'permission'];

    /**
     * The deepest a flag definition, an evaluator rule or the document metadata may nest (R-depth-validate): the
     * definition itself is level 1, and each object or array inside adds one.
     */
    public const int MAX_DEPTH = 256;

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
     * A document that is not an object is refused with the key `<document>`. Each section is empty when absent,
     * null or `[]`; a present section that is not an object is refused with the section's name as the key. A single
     * flag that breaks a rule is reported with its own key, an evaluator rule that is not an object with the key
     * `$evaluators` (the reason names the evaluator). A `$ref` naming no evaluator is not a load error: evaluating
     * that flag reports it.
     *
     * @throws InvalidFlagDefinition
     */
    public static function parseDocument(mixed $document): FlagDocument
    {
        if (! Json::isObject($document)) {
            throw new InvalidFlagDefinition('<document>', 'document must be an object');
        }

        $members = Json::members($document);

        $definitions = [];
        foreach (self::section($members, 'flags', 'flags must be an object') as $key => $definition) {
            $key = (string) $key;
            self::validate($key, $definition);
            $definitions[$key] = FlagDefinition::fromJsonValue($key, self::emptyListsAsObjects(Json::members($definition)));
        }

        $evaluators = self::section($members, '$evaluators', '$evaluators must be an object');
        foreach ($evaluators as $name => $rule) {
            if (! Json::isObject($rule)) {
                throw new InvalidFlagDefinition('$evaluators', sprintf("targeting must be an object (evaluator '%s')", $name));
            }
            $reason = self::walkReason($rule);
            if ($reason !== null) {
                throw new InvalidFlagDefinition('$evaluators', sprintf("%s (evaluator '%s')", $reason, $name));
            }
        }

        $metadata = self::section($members, 'metadata', 'metadata must be an object');
        $reason = self::metadataReason($metadata);
        if ($reason !== null) {
            throw new InvalidFlagDefinition('metadata', $reason);
        }
        $reason = self::walkReason($metadata);
        if ($reason !== null) {
            throw new InvalidFlagDefinition('metadata', $reason);
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

        if (! Json::isObject($definition)) {
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

        $reason = self::walkReason($definition);
        if ($reason !== null) {
            throw new InvalidFlagDefinition($key, $reason);
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
     * A validated flag's members with a `[]` targeting or metadata replaced by `{}`: the same JSON value, so the
     * definition still encodes as written, and a reader of the raw definition meets the contract's "no targeting".
     *
     * @param  array<array-key, mixed>  $members
     * @return array<array-key, mixed>
     */
    private static function emptyListsAsObjects(array $members): array
    {
        foreach (['targeting', 'metadata'] as $position) {
            if (array_key_exists($position, $members) && $members[$position] === []) {
                $members[$position] = new stdClass;
            }
        }

        return $members;
    }

    /**
     * The members of a document section: none when absent, null or `[]`.
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
     * Why $value (a flag definition, an evaluator rule or the document metadata: level 1) breaks the two rules that
     * reach its every corner, or null. `definition nests too deeply` when an object or array sits deeper than
     * MAX_DEPTH (reported first, whatever else the walk met); otherwise `numbers must be finite` when INF or NAN
     * stands anywhere — JSON has neither (YAML's `.inf`/`.nan` and PHP produce them) and encoding one throws, so the
     * definition could be neither composed, stored nor served. Walks with a stack of its own and never descends past
     * MAX_DEPTH: no recursion, and bounded work whatever the input's depth.
     */
    private static function walkReason(mixed $value): ?string
    {
        $nonFinite = false;
        /** @var list<array{mixed, int}> $pending */
        $pending = [[$value, 1]];
        while ($pending !== []) {
            [$item, $level] = array_pop($pending);
            if ($item instanceof stdClass) {
                $item = get_object_vars($item);
            }
            if (is_array($item)) {
                if ($level > self::MAX_DEPTH) {
                    return 'definition nests too deeply';
                }
                foreach ($item as $member) {
                    $pending[] = [$member, $level + 1];
                }
            } elseif (is_float($item) && ! is_finite($item)) {
                $nonFinite = true;
            }
        }

        return $nonFinite ? 'numbers must be finite' : null;
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
