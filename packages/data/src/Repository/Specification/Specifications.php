<?php

declare(strict_types=1);

namespace Firefly\Data\Repository\Specification;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Static factories for building and composing specifications (the design's `Specification::allOf/anyOf/not/where`,
 * relocated off the interface because PHP interfaces cannot carry static bodies). `allOf`/`anyOf` fold the
 * combinator classes left-to-right; a zero-arg fold yields a match-all specification.
 *
 * Bound to `Specification<Model>` throughout (not a per-call `@template TModel`): a PHP static factory has no
 * argument evidence to infer a caller-specific TModel from in the zero-arg case, and PHPStan flags a template
 * that appears only in a return type as unresolvable (`method.templateTypeNotInParameter`). `Model` is also
 * exactly the type the ad hoc `where()` closures build against (a bare `Builder $q` has no generic annotation)
 * and what `EloquentRepository::findBySpecification(/Paged)` accepts — the same "internal seam" convention
 * `query()`/`applySort()` already use, narrowed back to `TModel` at the terminal via `narrow()`. Callers who want
 * a `Specification<TModel>` for a specific entity can still construct the combinator classes directly.
 */
final class Specifications
{
    /**
     * @param  Specification<Model>  ...$specifications
     * @return Specification<Model>
     */
    public static function allOf(Specification ...$specifications): Specification
    {
        if ($specifications === []) {
            return self::matchAll();
        }

        return self::fold(array_values($specifications), static fn (Specification $l, Specification $r): Specification => new AndSpecification($l, $r));
    }

    /**
     * @param  Specification<Model>  ...$specifications
     * @return Specification<Model>
     */
    public static function anyOf(Specification ...$specifications): Specification
    {
        if ($specifications === []) {
            return self::matchAll();
        }

        return self::fold(array_values($specifications), static fn (Specification $l, Specification $r): Specification => new OrSpecification($l, $r));
    }

    /**
     * @param  Specification<Model>  $specification
     * @return Specification<Model>
     */
    public static function not(Specification $specification): Specification
    {
        return new NotSpecification($specification);
    }

    /**
     * @param  Closure(Builder<Model>): mixed  $callback
     * @return Specification<Model>
     */
    public static function where(Closure $callback): Specification
    {
        return new CallableSpecification($callback);
    }

    /**
     * A no-op specification: `WHERE true` in effect, since `toBuilder` applies no constraint. The identity for
     * `allOf`/`anyOf`'s zero-arg case.
     *
     * @return Specification<Model>
     */
    private static function matchAll(): Specification
    {
        return new CallableSpecification(static fn (Builder $q): Builder => $q);
    }

    /**
     * Folds a non-empty list left-to-right with $combine.
     *
     * @param  non-empty-list<Specification<Model>>  $specifications
     * @param  callable(Specification<Model>, Specification<Model>): Specification<Model>  $combine
     * @return Specification<Model>
     */
    private static function fold(array $specifications, callable $combine): Specification
    {
        $result = array_shift($specifications);

        foreach ($specifications as $specification) {
            $result = $combine($result, $specification);
        }

        return $result;
    }
}
