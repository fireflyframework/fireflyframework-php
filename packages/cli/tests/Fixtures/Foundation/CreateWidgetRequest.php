<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Fixtures\Foundation;

use Firefly\Validation\Constraint\NotBlank;

/** The #[Valid] request DTO for POST /widgets — a blank `name` fails NotBlank and renders a 422. Mirrors the web
 *  capstone's CreateAccountRequest constraint-attribute shape. */
final class CreateWidgetRequest
{
    public function __construct(
        #[NotBlank]
        public readonly string $name,
    ) {}
}
