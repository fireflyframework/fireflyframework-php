<?php

declare(strict_types=1);

namespace Firefly\Validation\Tests\Fixtures\Constraint;

use Firefly\Validation\Constraint\Max;
use Firefly\Validation\Constraint\NotBlank;
use Firefly\Validation\Constraint\Pattern;
use Firefly\Validation\Constraint\Positive;
use Firefly\Validation\Constraint\Rules;

/**
 * Every shape the field-error mapper has to get right on ONE payload:
 *  - $sku       #[NotBlank] + #[Pattern] — two constraints that both emit a `regex` rule, told apart by parameters;
 *  - $quantity  #[Positive] + #[Max]     — two constraints that both emit `numeric`, collapsed to one violation each;
 *  - $name      a `message:` element that must win over the default sentence;
 *  - $tag       #[Rules] without a message — keeps Laravel's sentence, is still attributed to `Rules`;
 *  - $label     #[Rules] with a message.
 */
final class SkuPayload
{
    public function __construct(
        #[NotBlank]
        #[Pattern('/^[A-Z0-9][A-Z0-9-]{2,31}$/D')]
        public readonly string $sku,
        #[Positive]
        #[Max(999)]
        public readonly int $quantity,
        #[NotBlank(message: 'give us a name')]
        public readonly string $name,
        #[Rules('min:3')]
        public readonly string $tag,
        #[Rules('min:3', message: 'must be at least 3 characters')]
        public readonly string $label,
    ) {}
}
