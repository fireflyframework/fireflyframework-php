<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\User;

/** Loads the principal of a plain OAuth2 login from the provider (Spring's OAuth2UserService). Bind your own to map a provider the default cannot. */
interface OAuth2UserService
{
    public function loadUser(OAuth2UserRequest $userRequest): OAuth2User;
}
