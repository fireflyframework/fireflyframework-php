<?php

declare(strict_types=1);

namespace Firefly\Tests\Browser\Support;

use Firefly\Security\Web\EntryPoint\DelegatingAuthenticationEntryPoint;

/**
 * SecuredBrowserTestCase with the entry point on `auto`: an anonymous browser at a protected URL is sent to
 * the framework's login page with the request saved, and comes back to it after signing in — Spring's
 * LoginUrlAuthenticationEntryPoint + SavedRequest, as a person sees them. An API path (json-paths, `api/*`)
 * still gets the 401 problem document, never a redirect. The sign-in flow scenarios sit here; the 401/403
 * page scenarios stay on the parent, whose `problem` entry point is what keeps a 401 page to photograph.
 */
abstract class SignedInBrowserTestCase extends SecuredBrowserTestCase
{
    protected function entryPoint(): string
    {
        return DelegatingAuthenticationEntryPoint::AUTO;
    }
}
