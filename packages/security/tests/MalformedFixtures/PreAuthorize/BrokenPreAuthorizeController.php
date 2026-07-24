<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\MalformedFixtures\PreAuthorize;

use Firefly\Security\Access\Attributes\PreAuthorize;

/**
 * Deliberately malformed #[PreAuthorize] (unterminated call, missing closing paren + argument) — kept in its own
 * namespace/directory so scanning it never contaminates the happy-path Fixtures/ scan tests. Exists solely to
 * prove MethodSecurityScanner::scan() fails loud (ConfigurationException) instead of shipping a silently-broken
 * manifest entry.
 */
final class BrokenPreAuthorizeController
{
    #[PreAuthorize('hasRole(')]
    public function show(): void {}
}
