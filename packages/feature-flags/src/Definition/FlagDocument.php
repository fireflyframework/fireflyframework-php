<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Definition;

use stdClass;

/**
 * A flagd document: flags, shared `$evaluators` and document metadata. fromJsonValue() TRUSTS its input —
 * every source validates through FlagDefinitions before a document reaches the registry; the evaluator and the
 * conformance runners read raw documents through it.
 *
 * Every map here is keyed by array-key: PHP stores a numeric-looking name (`"2024"`) as an int key. Read a flag's
 * key from FlagDefinition::$key or keys(), and any other name with `(string) $name`.
 *
 * `readonly` is shallow: a stdClass inside a definition, an evaluator or a metadata value is the same instance
 * every reader of the document gets. Treat returned values as read-only.
 */
final readonly class FlagDocument
{
    /**
     * @param  array<array-key, FlagDefinition>  $flags  keyed by flag key (PHP stores "2024" as int 2024: read FlagDefinition::$key)
     * @param  array<array-key, mixed>  $evaluators
     * @param  array<array-key, mixed>  $metadata  as decoded: a non-scalar value is kept so validation can reject it
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
            $flags[$key] = FlagDefinition::fromJsonValue((string) $key, $definition);
        }

        return new self($flags, Json::members($members['$evaluators'] ?? null), Json::members($members['metadata'] ?? null));
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

    /** @throws \JsonException as Json::encode() (INF or NAN in a definition) */
    public function toJson(): string
    {
        return Json::encode($this->toJsonValue());
    }

    /**
     * sha256 of the canonical form: independent of member order, sensitive to every value.
     *
     * @throws \JsonException as Json::encode()
     */
    public function fingerprint(): string
    {
        return hash('sha256', Json::canonical($this->toJsonValue()));
    }

    /**
     * Flag key => sha256 of that flag's canonical definition, sorted by key as text. A numeric-looking key is an
     * int key (PHP arrays cannot hold it as a string): read it with `(string) $key`.
     *
     * @return array<array-key, string>
     *
     * @throws \JsonException as Json::encode()
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
