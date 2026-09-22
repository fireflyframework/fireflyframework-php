<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Oidc;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT;
use Firefly\Security\OAuth2\Client\Token\OAuth2AuthenticationException;
use Firefly\Security\OAuth2\Client\Token\OAuth2Error;
use Firefly\Security\OAuth2\Client\Token\OAuth2ErrorCodes;
use Firefly\Security\OAuth2\JwksProvider;
use Throwable;

/**
 * Verifies an id token's signature and expiry against the provider's key set and reads its claims (Spring's
 * NimbusJwtDecoder as OidcIdTokenDecoderFactory configures it). The keys come from the JwksProvider port —
 * RemoteJwksProvider in production, InMemoryJwksProvider in a test — so verification never makes a live
 * request on the hot path, and a key set that cannot be fetched is the provider's own JwksUnavailableException
 * (a 503), resolved BEFORE the try that maps decoding failures, for the reason OAuth2ResourceServerFilter
 * gives: the token was never examined.
 *
 * `exp` is demanded (firebase checks it when present; a token without one is refused here), and `clock_skew`
 * is the leeway firebase applies to exp/nbf/iat. firebase reads that leeway off a STATIC (JWT::$leeway), so it
 * is set for the one decode and put back in a `finally`: the resource-server filter decodes bearer tokens with
 * its own leeway in the same process, and an id-token login must not change what it accepts. The claims are
 * read as a deep array (firebase hands back stdClass objects, nested ones included), so a `groups` list or an
 * `address` object is a PHP array.
 */
final class OidcIdTokenDecoder
{
    public function __construct(
        private readonly JwksProvider $jwks,
        private readonly int $clockSkewSeconds,
    ) {}

    public function decode(string $idToken, string $registrationId): OidcIdToken
    {
        $keys = $this->jwks->keys();

        $leeway = JWT::$leeway;
        JWT::$leeway = $this->clockSkewSeconds;

        try {
            $decoded = JWT::decode($idToken, $keys);
        } catch (ExpiredException $e) {
            throw new OAuth2AuthenticationException($registrationId, new OAuth2Error(OAuth2ErrorCodes::INVALID_ID_TOKEN, 'The id token has expired.'), $e);
        } catch (Throwable $e) {
            throw new OAuth2AuthenticationException($registrationId, new OAuth2Error(OAuth2ErrorCodes::INVALID_ID_TOKEN, "The id token signature could not be verified against the provider's keys."), $e);
        } finally {
            JWT::$leeway = $leeway;
        }

        /** @var array<string, mixed> $claims */
        $claims = json_decode(json_encode($decoded, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);
        if (! array_key_exists('exp', $claims)) {
            throw new OAuth2AuthenticationException($registrationId, new OAuth2Error(OAuth2ErrorCodes::INVALID_ID_TOKEN, 'The id token carries no exp claim.'));
        }

        return new OidcIdToken($idToken, $claims);
    }
}
