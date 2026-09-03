<?php

declare(strict_types=1);

namespace Firefly\Admin\Data;

use Firefly\Actuator\Introspection\SensitiveValueMasker;

/**
 * One displayable field of a browsable resource: its name, a display type, whether it may be null, whether it
 * is THE identifier, and whether its name marks it as a secret.
 *
 * THE TYPE VOCABULARY IS CLOSED, AND DELIBERATELY SMALL — string, int, bool, datetime, json. It is a
 * RENDERING hint, not a schema echo: the browser has to decide "right-align this", "draw a checkbox", "format
 * this as a timestamp", "pretty-print this blob", and there are only those four decisions plus a default. A
 * richer vocabulary would have to be kept in sync with every driver's type names (sqlite alone answers
 * `varchar`, `numeric`, `tinyint`, `text` for things Laravel's schema builder was asked to create as string,
 * decimal, boolean and json) and would buy the view nothing.
 *
 * WHY DECIMAL AND FLOAT MAP TO `string`, NOT TO A NUMERIC TYPE. A `decimal(10,2)` column arrives from PDO as
 * the string "10.10", and that is not an accident of the driver — it is how the value survives a round trip
 * without binary floating point eating the last cent. Typing it `int`/`float` invites the view to format it
 * as a number, and the first thing a number formatter does to "10.10" is render it as 10.1. A browser that
 * silently rewrites a money column is worse than one that shows the raw text, so the raw text is what the
 * type promises. `int` is reserved for genuinely integral columns — keys, counters, foreign keys — where
 * right-aligning is correct and no precision can be lost.
 *
 * SENSITIVITY IS DECIDED BY NAME, HERE, ONCE. The rule is Actuator's SensitiveValueMasker — the same regex
 * that masks /env and /configprops — reused rather than mirrored, because the layer graph already permits
 * Admin -> Actuator and a second copy of a masking list is how a masking list rots (see that class's own
 * docblock for the argument). A column called `api_token` is masked in the listing, in the detail view, and
 * is refused as an update target; see DataBrowser::update() for why the refusal matters as much as the mask.
 */
final readonly class DataColumn
{
    public const string TYPE_STRING = 'string';

    public const string TYPE_INT = 'int';

    public const string TYPE_BOOL = 'bool';

    public const string TYPE_DATETIME = 'datetime';

    public const string TYPE_JSON = 'json';

    public function __construct(
        public string $name,
        public string $type = self::TYPE_STRING,
        public bool $nullable = true,
        public bool $identifier = false,
        public bool $sensitive = false,
    ) {}

    /**
     * The named constructor every derivation path goes through, so sensitivity can never be forgotten by a
     * caller that happened to build a DataColumn by hand.
     */
    public static function of(string $name, string $type = self::TYPE_STRING, bool $nullable = true, bool $identifier = false): self
    {
        return new self(
            name: $name,
            type: self::normalizeType($type),
            nullable: $nullable,
            identifier: $identifier,
            sensitive: SensitiveValueMasker::isSensitive($name),
        );
    }

    /**
     * A column may be written from the browser only when it is neither the identifier nor a secret.
     *
     * The identifier is excluded because re-keying a row from a generic form is not an edit, it is a
     * different row: foreign keys pointing at the old value do not follow, and the browser has no way to know
     * which ones exist. The secret is excluded because its DISPLAYED value is `******` — round-tripping a
     * rendered form would write the mask over the real credential, which is a data-loss bug the masking
     * itself created. Both refusals are enforced again in DataBrowser::update(); this predicate exists so the
     * view can render the field as read-only instead of offering an edit that will be rejected.
     */
    public function isEditable(): bool
    {
        return ! $this->identifier && ! $this->sensitive;
    }

    /** `created_at` => `Created at`. Snake and kebab both split; nothing else is guessed. */
    public function label(): string
    {
        $words = preg_split('/[_\-]+/', $this->name) ?: [$this->name];

        return ucfirst(implode(' ', array_filter($words, static fn (string $word): bool => $word !== '')));
    }

    /** Any type name outside the closed vocabulary degrades to `string` rather than reaching the view. */
    private static function normalizeType(string $type): string
    {
        return in_array($type, [self::TYPE_STRING, self::TYPE_INT, self::TYPE_BOOL, self::TYPE_DATETIME, self::TYPE_JSON], true)
            ? $type
            : self::TYPE_STRING;
    }
}
