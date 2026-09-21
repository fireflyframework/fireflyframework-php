<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Web\Login;

use Firefly\Security\Core\Authentication;
use Firefly\Security\OAuth2\Client\Authorized\OAuth2AuthorizedClient;
use Firefly\Security\OAuth2\Client\User\OAuth2User;

/** What a successful login yields: the token to sign in with, the principal, and the authorized client to store. */
final readonly class OAuth2LoginAuthentication
{
    public function __construct(
        public Authentication $authentication,
        public OAuth2User $user,
        public OAuth2AuthorizedClient $authorizedClient,
    ) {}
}
