<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures;

use Firefly\Validation\Constraint\Iban;
use Firefly\Validation\Constraint\NotBlank;

final class CreateAccountRequest
{
    public function __construct(
        #[NotBlank]
        #[Iban]
        public readonly string $iban,
        #[NotBlank]
        public readonly string $owner,
    ) {}
}
