<?php

declare(strict_types=1);

namespace Firefly\Data\Repository\Specification;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Both sides must match. Each side is grouped in a nested closure so precedence survives further OR composition.
 *
 * @template TModel of Model
 *
 * @implements Specification<TModel>
 */
final readonly class AndSpecification implements Specification
{
    /**
     * @param  Specification<TModel>  $left
     * @param  Specification<TModel>  $right
     */
    public function __construct(
        private Specification $left,
        private Specification $right,
    ) {}

    public function toBuilder(Builder $query): Builder
    {
        return $query
            ->where(fn (Builder $inner) => $this->left->toBuilder($inner))
            ->where(fn (Builder $inner) => $this->right->toBuilder($inner));
    }
}
