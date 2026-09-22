<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\Advice;

final readonly class ArchiveCommand
{
    public function __construct(public int $id) {}
}
