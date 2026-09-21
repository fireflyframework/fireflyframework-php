<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\Advice;

/** A returned object with an owner, for hasPermission(#returnObject, ...) and hasPermission(#filterObject, ...). */
final readonly class Report
{
    public function __construct(public int $id, public string $owner) {}
}
