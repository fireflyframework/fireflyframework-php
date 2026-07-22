<?php

declare(strict_types=1);

namespace Firefly\Data\Repository\Specification;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Negates the wrapped specification via `whereNot(<grouped closure>)`.
 *
 * @template TModel of Model
 *
 * @implements Specification<TModel>
 */
final readonly class NotSpecification implements Specification
{
    /**
     * @param  Specification<TModel>  $specification
     */
    public function __construct(private Specification $specification) {}

    public function toBuilder(Builder $query): Builder
    {
        return $query->whereNot(fn (Builder $inner) => $this->specification->toBuilder($inner));
    }
}
