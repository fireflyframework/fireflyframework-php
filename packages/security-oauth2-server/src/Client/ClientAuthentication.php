<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Client;

/** An authenticated client and the method it used (Spring's OAuth2ClientAuthenticationToken, authenticated). */
final readonly class ClientAuthentication
{
    public function __construct(
        public RegisteredClient $client,
        public ClientAuthenticationMethod $method,
    ) {}
}
