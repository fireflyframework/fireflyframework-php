<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Registry;

use Firefly\FeatureFlags\Definition\FlagDefinition;
use Firefly\FeatureFlags\Definition\FlagDocument;
use Firefly\FeatureFlags\Definition\Json;
use stdClass;

/**
 * The effective flag set: each key's winning definition with its origin, plus the merged `$evaluators` and document
 * metadata. Every map is keyed by array-key: PHP stores a numeric-looking name (`"2024"`) as an int key, so read a
 * flag's key from FlagDefinition::$key and any other name with `(string) $name`.
 */
final readonly class Composition
{
    /**
     * @param  array<array-key, ComposedFlag>  $flags  keyed by flag key, sorted as text
     * @param  array<array-key, mixed>  $evaluators
     * @param  array<array-key, mixed>  $metadata
     */
    public function __construct(
        public array $flags = [],
        public array $evaluators = [],
        public array $metadata = [],
    ) {}

    public function flag(string $key): ?ComposedFlag
    {
        return $this->flags[$key] ?? null;
    }

    public function document(): FlagDocument
    {
        $definitions = [];
        foreach ($this->flags as $flag) {
            $definitions[$flag->definition->key] = $flag->definition;
        }

        return new FlagDocument($definitions, $this->evaluators, $this->metadata);
    }

    /**
     * Flag key => sha256 of everything an evaluation of that key reads, sorted by key as text: the whole composed
     * definition (fields flagd does not read included), the metadata it reports (the document's, overridden key by key
     * by its own) and every evaluator its targeting references, directly or through another evaluator (a name no
     * evaluator has counts as absent). Values compare as JSON does: `true` is not `1` and `1` is not `1.0`. Two
     * compositions answer a key differently only if its fingerprint differs, so FeatureFlagsChanged names exactly the
     * keys whose fingerprint moved. A numeric-looking key is an int key: read it with `(string) $key`.
     *
     * @return array<array-key, string>
     *
     * @throws \JsonException as Json::encode() (never for a validated document)
     */
    public function fingerprints(): array
    {
        $fingerprints = [];
        foreach ($this->flags as $flag) {
            $definition = $flag->definition;
            $fingerprints[$definition->key] = hash('sha256', Json::canonical([
                'definition' => $definition->toJsonValue(),
                'metadata' => Json::object(array_replace($this->metadata, $definition->metadata())),
                'evaluators' => Json::object($this->evaluatorsUsedBy($definition)),
            ]));
        }
        ksort($fingerprints, SORT_STRING);

        return $fingerprints;
    }

    /**
     * The evaluators $definition's targeting depends on, transitively: name => rule, null for a name no evaluator has.
     * References resolve inside targeting only (spec §4.1), so a variant that looks like one is a value.
     *
     * @return array<array-key, mixed>
     */
    private function evaluatorsUsedBy(FlagDefinition $definition): array
    {
        $used = [];
        $pending = self::references($definition->targeting());
        while ($pending !== []) {
            $name = array_pop($pending);
            if (array_key_exists($name, $used)) {
                continue;
            }
            $rule = $this->evaluators[$name] ?? null;
            $used[$name] = $rule;
            if ($rule !== null) {
                array_push($pending, ...self::references($rule));
            }
        }

        return $used;
    }

    /**
     * Every evaluator name $value references: a `{"$ref": name}` object with exactly one member, a text value, at any
     * depth (an object that also holds other members is not a reference). Walks with a stack of its own.
     *
     * @return list<string>
     */
    private static function references(mixed $value): array
    {
        $names = [];
        $pending = [$value];
        while ($pending !== []) {
            $node = array_pop($pending);
            if ($node instanceof stdClass) {
                $node = get_object_vars($node);
            }
            if (! is_array($node)) {
                continue;
            }
            if (count($node) === 1 && is_string($node['$ref'] ?? null)) {
                $names[] = $node['$ref'];

                continue;
            }
            foreach ($node as $child) {
                $pending[] = $child;
            }
        }

        return $names;
    }
}
