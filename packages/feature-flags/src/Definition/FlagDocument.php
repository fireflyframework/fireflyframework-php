<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Definition;

use stdClass;

/**
 * A flagd document: flags, shared `$evaluators` and document metadata. fromJsonValue() TRUSTS its input —
 * every source validates through FlagDefinitions before a document reaches the registry; the evaluator and the
 * conformance runners read raw documents through it.
 */
final readonly class FlagDocument
{
    /**
     * @param  array<array-key, FlagDefinition>  $flags  keyed by flag key (PHP stores "2024" as int 2024: read FlagDefinition::$key)
     * @param  array<array-key, mixed>  $evaluators
     * @param  array<string, bool|int|float|string>  $metadata
     */
    public function __construct(
        public array $flags = [],
        public array $evaluators = [],
        public array $metadata = [],
    ) {}

    public static function fromJsonValue(mixed $document): self
    {
        $members = Json::members($document);

        $flags = [];
        foreach (Json::members($members['flags'] ?? null) as $key => $definition) {
            $flags[(string) $key] = FlagDefinition::fromJsonValue((string) $key, $definition);
        }

        $metadata = [];
        foreach (Json::members($members['metadata'] ?? null) as $name => $value) {
            if (is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
                $metadata[(string) $name] = $value;
            }
        }

        return new self($flags, Json::members($members['$evaluators'] ?? null), $metadata);
    }

    /** @throws \JsonException */
    public static function fromJson(string $json): self
    {
        return self::fromJsonValue(Json::decode($json));
    }

    public function flag(string $key): ?FlagDefinition
    {
        return $this->flags[$key] ?? null;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(static fn (FlagDefinition $flag): string => $flag->key, array_values($this->flags));
    }

    /** `{"flags":{…},"$evaluators":{…},"metadata":{…}}`, always all three, each an object even when empty. */
    public function toJsonValue(): stdClass
    {
        $flags = [];
        foreach ($this->flags as $flag) {
            $flags[$flag->key] = $flag->toJsonValue();
        }

        return (object) [
            'flags' => Json::object($flags),
            '$evaluators' => Json::object($this->evaluators),
            'metadata' => Json::object($this->metadata),
        ];
    }

    public function toJson(): string
    {
        return Json::encode($this->toJsonValue());
    }

    /** sha256 of the canonical form: independent of member order, sensitive to every value. */
    public function fingerprint(): string
    {
        return hash('sha256', Json::canonical($this->toJsonValue()));
    }

    /**
     * Flag key => sha256 of that flag's canonical definition, sorted by key. A numeric-looking key is an int
     * key at runtime (PHP arrays): cast it with `(string)` where a string is needed.
     *
     * @return array<string, string>
     */
    public function keyFingerprints(): array
    {
        $fingerprints = [];
        foreach ($this->flags as $flag) {
            $fingerprints[$flag->key] = hash('sha256', Json::canonical($flag->toJsonValue()));
        }
        ksort($fingerprints, SORT_STRING);

        return $fingerprints;
    }
}
