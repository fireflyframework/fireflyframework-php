<?php

declare(strict_types=1);

namespace Firefly\Data\Repository\Attributes;

use Attribute;

/**
 * An explicit-query override for a repository method: the given SQL runs instead of a derived query. Named
 * `:param` placeholders bind to the method's arguments. Discovered by the TransactionalScanner (the data
 * package's one scanner) into the manifest so a later chunk's EloquentRepository::__call routes without
 * per-request reflection. `native` selects a raw/native SQL dialect where relevant.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class Query
{
    public function __construct(
        public string $sql,
        public bool $native = false,
    ) {}
}
