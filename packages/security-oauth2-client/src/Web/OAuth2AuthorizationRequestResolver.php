<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Web;

use Firefly\Security\OAuth2\Client\Registration\ClientRegistration;
use Firefly\Security\OAuth2\Client\Registration\RedirectUriTemplate;

/**
 * Builds the authorization request for a registration (Spring's DefaultOAuth2AuthorizationRequestResolver):
 * a fresh `state` every time (32 random bytes, base64url — 43 characters), a `nonce` whenever `openid` is
 * requested (OIDC Core §3.1.2.1 makes it optional; this package makes it mandatory, because the nonce is
 * what ties the id token to THIS browser's request and there is no reason to ever leave it out), a PKCE
 * verifier whenever the registration uses PKCE (by default; always for a public client), and the redirect
 * URI expanded from the application's base URL. The three random values share one generator: 256 bits from
 * random_bytes(), and the base64url alphabet is a subset of the RFC 7636 verifier alphabet.
 */
final class OAuth2AuthorizationRequestResolver
{
    public function resolve(ClientRegistration $registration, string $baseUrl): OAuth2AuthorizationRequest
    {
        return new OAuth2AuthorizationRequest(
            registrationId: $registration->registrationId,
            authorizationUri: $registration->providerDetails->authorizationUri,
            clientId: $registration->clientId,
            redirectUri: RedirectUriTemplate::expand($registration->redirectUri, $baseUrl, $registration->registrationId),
            scopes: $registration->scopes,
            state: self::random(),
            nonce: $registration->usesOpenId() ? self::random() : null,
            codeVerifier: $registration->usesPkce() ? self::random() : null,
        );
    }

    /** 32 random bytes as base64url: 43 characters of `[A-Za-z0-9_-]`. */
    public static function random(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
