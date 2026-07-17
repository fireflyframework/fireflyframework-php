<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures;

use Firefly\Validation\Constraint\NotBlank;

final class PartialBodyRequest
{
    public function __construct(
        #[NotBlank]
        public readonly string $name,
        public readonly ?string $nickname = null,
    ) {}
}
