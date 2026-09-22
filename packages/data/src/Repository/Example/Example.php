<?php

declare(strict_types=1);

namespace Firefly\Data\Repository\Example;

use Firefly\Data\Repository\Specification\Specification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Query by example — Spring Data's Example<T>, ported as a SPECIFICATION. A probe (an Eloquent model with some
 * attributes set, or a plain column => value array) becomes a WHERE clause under an ExampleMatcher's rules, and
 * because it is a Specification it drives findBySpecification()/findBySpecificationPaged() directly and
 * composes with Specifications::allOf()/anyOf()/not() like any other predicate.
 *
 * A model probe contributes exactly the attributes that were SET, so `new Order(['status' => 'open'])` is a
 * one-property probe, not a probe of every column. They are read as the model's raw originals overlaid with
 * its dirty attributes — for a fresh model the originals are empty and every set attribute is dirty; for a
 * persisted model the originals are the row and the dirty set is what changed since — which is the model's
 * current attribute array in DB shape (casts already serialised), without naming Eloquent's own accessor for
 * it: this package's reflection-free guard (ReflectionFreeDataTest) keys on that method's name as the
 * signature of attribute reflection, and a runtime class must stay clear of it. An array probe contributes
 * itself.
 *
 * Every probe key is interpolated into a raw fragment when case is ignored or a LIKE needs its ESCAPE clause,
 * so keys are validated as bare identifiers here, at construction — the same guard DerivedQueryParser applies
 * to method-name fields, for the same reason.
 *
 * @implements Specification<Model>
 */
final readonly class Example implements Specification
{
    private const string IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*$/D';

    /**
     * @param  array<string, mixed>  $probe
     */
    private function __construct(
        public array $probe,
        public ExampleMatcher $matcher,
    ) {}

    /**
     * @param  object|array<string, mixed>  $probe
     */
    public static function of(object|array $probe, ?ExampleMatcher $matcher = null): self
    {
        $attributes = match (true) {
            $probe instanceof Model => [...$probe->getRawOriginal(), ...$probe->getDirty()],
            is_object($probe) => get_object_vars($probe),
            default => $probe,
        };

        $validated = [];
        foreach ($attributes as $key => $value) {
            $key = (string) $key;
            if (preg_match(self::IDENTIFIER, $key) !== 1) {
                throw new InvalidArgumentException("Example probe key [{$key}] is not a valid column identifier.");
            }
            $validated[$key] = $value;
        }

        return new self($validated, $matcher ?? ExampleMatcher::matching());
    }

    /**
     * The probe after the matcher's ignore rules — what is actually compared.
     *
     * @return array<string, mixed>
     */
    public function attributes(): array
    {
        $attributes = [];
        foreach ($this->probe as $column => $value) {
            if ($this->matcher->isIgnored($column)) {
                continue;
            }
            if ($value === null && ! $this->matcher->includesNullValues()) {
                continue;
            }
            $attributes[$column] = $value;
        }

        return $attributes;
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    public function toBuilder(Builder $query): Builder
    {
        $attributes = $this->attributes();
        if ($attributes === []) {
            return $query;
        }

        $boolean = $this->matcher->isAllMatching() ? 'and' : 'or';

        // Grouped, so an OR-folded example composed under allOf() keeps its own precedence.
        return $query->where(function (Builder $nested) use ($attributes, $boolean): void {
            foreach ($attributes as $column => $value) {
                $this->applyPredicate($nested, $column, $value, $boolean);
            }
        });
    }

    /**
     * @param  Builder<Model>  $query
     */
    private function applyPredicate(Builder $query, string $column, mixed $value, string $boolean): void
    {
        if ($value === null) {
            $query->whereNull($column, $boolean);

            return;
        }

        if (! is_string($value)) {
            $query->where($column, '=', $value, $boolean);

            return;
        }

        $ignoreCase = $this->matcher->isIgnoreCase($column);
        $stringMatcher = $this->matcher->stringMatcherFor($column);

        if ($stringMatcher === StringMatcher::EXACT) {
            if (! $ignoreCase) {
                $query->where($column, '=', $value, $boolean);

                return;
            }

            // $column is validated against IDENTIFIER in of(), so the LOWER(col) fragment is injection-free.
            // whereRaw types $sql as literal-string and a runtime-built string can never satisfy that.
            // @phpstan-ignore argument.type
            $query->whereRaw("LOWER({$column}) = LOWER(?)", [$value], $boolean);

            return;
        }

        $fragment = $ignoreCase
            ? "LOWER({$column}) like LOWER(?) escape '!'"
            : "{$column} like ? escape '!'";

        // Same validated-identifier argument as above; the pattern is a binding, never interpolated.
        // @phpstan-ignore argument.type
        $query->whereRaw($fragment, [$stringMatcher->pattern($value)], $boolean);
    }
}
