<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Definition;

use Firefly\FeatureFlags\Evaluation\FlagType;
use stdClass;

/**
 * One flagd flag object, kept exactly as written ($raw) so a definition passes through every layer unchanged.
 * The accessors read it the way flagd does; nothing here validates (FlagDefinitions does).
 */
final readonly class FlagDefinition
{
    /**
     * @param  array<array-key, mixed>  $raw  the flagd flag object in Json's faithful form
     */
    public function __construct(public string $key, public array $raw) {}

    public static function fromJsonValue(string $key, mixed $value): self
    {
        return new self($key, Json::members($value));
    }

    /** '' when absent. */
    public function state(): string
    {
        $state = $this->raw['state'] ?? null;

        return is_string($state) ? $state : '';
    }

    public function isDisabled(): bool
    {
        return $this->state() === 'DISABLED';
    }

    /**
     * Variant name => value. A numeric-looking name (`"1"`) is an int key here; variantNames() gives it as text.
     *
     * @return array<array-key, mixed>
     */
    public function variants(): array
    {
        return Json::members($this->raw['variants'] ?? null);
    }

    /**
     * @return list<string>
     */
    public function variantNames(): array
    {
        return array_map(static fn (int|string $name): string => (string) $name, array_keys($this->variants()));
    }

    public function hasVariant(string $name): bool
    {
        return array_key_exists($name, $this->variants());
    }

    public function variantValue(string $name): mixed
    {
        return $this->variants()[$name] ?? null;
    }

    /** A boolean names the variant `'true'`/`'false'`; `''` or null is no default variant. */
    public function defaultVariant(): ?string
    {
        $default = $this->raw['defaultVariant'] ?? null;
        if (is_bool($default)) {
            return $default ? 'true' : 'false';
        }

        return is_string($default) && $default !== '' ? $default : null;
    }

    /** null when absent, null or an empty object. */
    public function targeting(): mixed
    {
        $targeting = $this->raw['targeting'] ?? null;

        if ($targeting === null || $targeting === [] || ($targeting instanceof stdClass && get_object_vars($targeting) === [])) {
            return null;
        }

        return $targeting;
    }

    /**
     * The scalar metadata entries. A numeric-looking name is an int key at runtime (PHP arrays): cast it with
     * `(string)` where a string is needed.
     *
     * @return array<string, bool|int|float|string>
     */
    public function metadata(): array
    {
        $metadata = [];
        foreach (Json::members($this->raw['metadata'] ?? null) as $name => $value) {
            if (is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
                $metadata[(string) $name] = $value;
            }
        }

        return $metadata;
    }

    /** 'boolean', 'string', 'number' or 'object', read off the first variant. */
    public function valueType(): string
    {
        $values = array_values($this->variants());
        $first = $values[0] ?? null;

        return match (true) {
            is_bool($first) => 'boolean',
            is_int($first), is_float($first) => 'number',
            is_string($first) => 'string',
            default => 'object',
        };
    }

    /** A number flag is Integer when every variant is an int, else Float. */
    public function flagType(): FlagType
    {
        return match ($this->valueType()) {
            'boolean' => FlagType::Boolean,
            'string' => FlagType::String,
            'number' => array_filter($this->variants(), static fn (mixed $value): bool => ! is_int($value)) === [] ? FlagType::Integer : FlagType::Float,
            default => FlagType::Object,
        };
    }

    public function expires(): ?string
    {
        $expires = $this->metadata()['expires'] ?? null;

        return is_string($expires) ? $expires : null;
    }

    /** `expires` is before `$today` (both YYYY-MM-DD, UTC): the flag still evaluates, it is flag debt. */
    public function isExpired(string $today): bool
    {
        $expires = $this->expires();

        return $expires !== null && strcmp($expires, $today) < 0;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->raw;
    }

    /**
     * The definition as it must be ENCODED: the three object positions a PHP array may have flattened to `[]`
     * (config written as PHP arrays) are forced back to objects.
     *
     * @return stdClass|array<array-key, mixed>
     */
    public function toJsonValue(): stdClass|array
    {
        $value = $this->raw;
        foreach (['variants', 'metadata', 'targeting'] as $position) {
            if (array_key_exists($position, $value) && (is_array($value[$position]) || $value[$position] instanceof stdClass)) {
                $value[$position] = Json::object($value[$position]);
            }
        }

        return Json::object($value);
    }
}
