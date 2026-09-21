<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\User;

/** Loads the principal of an OpenID Connect login (Spring's OidcUserService). Bind your own to change what an OidcUser is built from. */
interface OidcUserService
{
    public function loadUser(OidcUserRequest $userRequest): OidcUser;
}
