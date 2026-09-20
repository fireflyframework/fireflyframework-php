<?php

declare(strict_types=1);

namespace Firefly\Data\Repository\Attributes;

use Attribute;

/**
 * Hydrates each row of a derived-query or #[Query] method into $dto through its constructor: snake_case column
 * to camelCase parameter, scalar coercion from the parameter's type, backed enums via from(), DateTimeImmutable
 * from the string. On a derived method the SELECT list is `$columns`, or the parameters' columns when empty;
 * on a #[Query] method the SQL owns its select list. Spring Data's class-based (DTO) projection; interface
 * projections are not offered — PHP has no proxy to implement an interface by column name at runtime.
 * INERT METADATA: the DTO's constructor is reflected once by TransactionalScanner into the manifest, and
 * Firefly\Data\Repository\Projection\ProjectionHydrator consumes that row at dispatch.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Projection
{
    /**
     * @param  class-string  $dto
     * @param  list<string>  $columns
     */
    public function __construct(
        public string $dto,
        public array $columns = [],
    ) {}
}
