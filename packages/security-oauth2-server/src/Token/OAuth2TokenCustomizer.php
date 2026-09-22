<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Token;

/**
 * The application's hook into every JWT this server signs (Spring's OAuth2TokenCustomizer<JwtEncodingContext>):
 * bind one and it runs LAST for each access token and id token. The shipped access token carries scopes and no
 * authorities; a customizer that wants OAuth2ResourceServerFilter to see roles adds the `roles` claim (its
 * `authorities_claim`) from `$context->principal`.
 */
interface OAuth2TokenCustomizer
{
    public function customize(JwtEncodingContext $context): void;
}
