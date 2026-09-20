<?php

declare(strict_types=1);

namespace Firefly\Security\Event;

use Firefly\Security\Core\Authentication;

/**
 * An authenticated principal was refused by a rule (Spring's AuthorizationDeniedEvent). $subject names what was
 * refused — `App\Reports::totals` for a method rule, `GET /admin/users` for a URL rule — and $expression is the
 * rule that refused, when there was one. Anonymous refusals are 401s and publish nothing: they are an
 * authentication problem, not an authorization decision.
 */
final readonly class AuthorizationDeniedEvent
{
    public function __construct(
        public Authentication $authentication,
        public string $subject,
        public ?string $expression,
    ) {}
}
