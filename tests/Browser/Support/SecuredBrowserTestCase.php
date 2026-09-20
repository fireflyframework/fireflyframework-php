<?php

declare(strict_types=1);

namespace Firefly\Tests\Browser\Support;

/**
 * Deny-by-default URL security with one rule: every path needs ROLE_ADMIN. An anonymous browser gets the
 * 401 page; `?as=user` (see FixturePrincipalFilter) is authenticated but not an admin, and gets the 403.
 */
abstract class SecuredBrowserTestCase extends BrowserTestCase
{
    protected function securityOverrides(): array
    {
        return [
            'firefly.security.enabled' => true,
            'firefly.security.http.enabled' => true,
            'firefly.security.http.rules' => [
                ['pattern' => '*', 'access' => 'hasRole:ADMIN'],
            ],
        ];
    }
}
