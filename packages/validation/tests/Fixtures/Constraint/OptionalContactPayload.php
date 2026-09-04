<?php

declare(strict_types=1);

namespace Firefly\Validation\Tests\Fixtures\Constraint;

use Firefly\Validation\Constraint\Email;
use Firefly\Validation\Constraint\NotBlank;
use Firefly\Validation\Constraint\NotNull;

/**
 * The regression fixture for Jakarta's null contract. Three shapes on one payload:
 *  - $primaryEmail  #[NotNull] + #[Email] — null is rejected by @NotNull, and only by @NotNull.
 *  - $backupEmail   #[Email] alone        — a present-but-null value is VALID (nothing asked for a value).
 *  - $displayName   #[NotBlank]           — @NotBlank subsumes @NotNull, so null is still rejected.
 */
final class OptionalContactPayload
{
    public function __construct(
        #[NotNull]
        #[Email]
        public readonly ?string $primaryEmail,
        #[Email]
        public readonly ?string $backupEmail = null,
        #[NotBlank]
        public readonly ?string $displayName = null,
    ) {}
}
