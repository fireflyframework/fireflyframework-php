<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Oidc;

use Firefly\Security\OAuth2\Client\Registration\ClientRegistration;
use Firefly\Security\OAuth2\Client\Token\OAuth2AuthenticationException;
use Firefly\Security\OAuth2\Client\Token\OAuth2Error;
use Firefly\Security\OAuth2\Client\Token\OAuth2ErrorCodes;

/**
 * The claim checks of OIDC Core §3.1.3.7, after the signature and `exp` have been verified by the decoder
 * (Spring's OidcIdTokenValidator):
 *
 *   iss    equals the registration's issuer when one is known (a trailing slash is not a different issuer);
 *   aud    contains the client_id;
 *   azp    when present, equals the client_id; when aud names more than one audience, it is REQUIRED;
 *   iat    present;
 *   sub    present;
 *   nonce  equals the one sent with the authorization request, compared in constant time — always, for an
 *          `openid` login, because the resolver always mints one.
 *
 * A wrong nonce is `invalid_nonce`, everything else `invalid_id_token`; the descriptions name the claim.
 */
final class OidcIdTokenValidator
{
    public static function validate(OidcIdToken $idToken, ClientRegistration $registration, ?string $expectedNonce): void
    {
        $id = $registration->registrationId;

        $issuer = $registration->providerDetails->issuerUri;
        if ($issuer !== null && rtrim($idToken->getIssuer() ?? '', '/') !== rtrim($issuer, '/')) {
            throw self::invalid($id, "The id token was not issued by the registration's issuer (iss).");
        }

        $audience = $idToken->getAudience();
        if (! in_array($registration->clientId, $audience, true)) {
            throw self::invalid($id, 'The id token is not intended for this client (aud).');
        }

        $azp = $idToken->getAuthorizedParty();
        if (count($audience) > 1 && $azp === null) {
            throw self::invalid($id, 'The id token names several audiences and no authorized party (azp).');
        }
        if ($azp !== null && $azp !== $registration->clientId) {
            throw self::invalid($id, "The id token's authorized party (azp) is not this client.");
        }

        if ($idToken->getIssuedAt() === null) {
            throw self::invalid($id, 'The id token carries no iat claim.');
        }
        if ($idToken->getSubject() === '') {
            throw self::invalid($id, 'The id token carries no sub claim.');
        }

        if ($expectedNonce !== null) {
            $nonce = $idToken->getNonce();
            if ($nonce === null || ! hash_equals($expectedNonce, $nonce)) {
                throw new OAuth2AuthenticationException($id, new OAuth2Error(OAuth2ErrorCodes::INVALID_NONCE, "The id token's nonce does not match the one sent with the authorization request."));
            }
        }
    }

    private static function invalid(string $registrationId, string $description): OAuth2AuthenticationException
    {
        return new OAuth2AuthenticationException($registrationId, new OAuth2Error(OAuth2ErrorCodes::INVALID_ID_TOKEN, $description));
    }
}
