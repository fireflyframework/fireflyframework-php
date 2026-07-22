<?php

declare(strict_types=1);

namespace Firefly\Data\Repository\Specification;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Wraps a caller-supplied closure that constrains the Builder. The closure mutates the Builder in place; the
 * same Builder is returned so it composes with the other combinators.
 *
 * @template TModel of Model
 *
 * @implements Specification<TModel>
 */
final readonly class CallableSpecification implements Specification
{
    /**
     * @param  Closure(Builder<TModel>): mixed  $callback
     */
    public function __construct(private Closure $callback) {}

    public function toBuilder(Builder $query): Builder
    {
        ($this->callback)($query);

        return $query;
    }
}
