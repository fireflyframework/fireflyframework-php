<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\Cqrs;

/** The query-side twin of FailingCommand. */
final class FailingQuery
{
    public function __construct(public string $failure) {}
}
