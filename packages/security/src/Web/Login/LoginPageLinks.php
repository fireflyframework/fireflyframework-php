<?php

declare(strict_types=1);

namespace Firefly\Security\Web\Login;

use Illuminate\Http\Request;

/**
 * Extra ways to sign in that the login page lists under the form (Spring's DefaultLoginPageGeneratingFilter and
 * its oauth2AuthenticationUrlToClientName map): firefly/security-oauth2-client binds one that names every
 * authorization-code registration, and an application may bind its own. The port lives here, in the package
 * that owns the page, so the page never depends on who fills it. Asked per render, with the request, so a link
 * can be root-relative to the base path the page was fetched under.
 */
interface LoginPageLinks
{
    /** @return list<LoginPageLink> */
    public function links(Request $request): array;
}
