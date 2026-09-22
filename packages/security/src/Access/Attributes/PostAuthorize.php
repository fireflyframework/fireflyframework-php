<?php

declare(strict_types=1);

namespace Firefly\Security\Access\Attributes;

use Attribute;

/**
 * Guards a method's RESULT: the expression runs after the call with `#returnObject` bound to what the method
 * returned — `hasPermission(#returnObject, 'READ')` is the shape, because the whitelist grammar reaches an
 * object only through the PermissionEvaluator. A refusal discards the result and answers 403 (or 401 when
 * anonymous) with the same `code`/`message` vocabulary as #[PreAuthorize]. Enforced on a proxied bean and at
 * the controller dispatcher; the CQRS bus authorises before dispatch only and cannot see a result.
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class PostAuthorize
{
    public function __construct(
        public string $expression,
        public ?string $code = null,
        public ?string $message = null,
    ) {}
}
