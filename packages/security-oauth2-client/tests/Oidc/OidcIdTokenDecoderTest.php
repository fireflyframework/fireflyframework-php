<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Client\Oidc\OidcIdTokenDecoder;
use Firefly\Security\OAuth2\Client\Token\OAuth2AuthenticationException;
use Firefly\Security\OAuth2\InMemoryJwksProvider;
use Firefly\Testing\Security\OAuth2\FakeAuthorizationServer;
use Illuminate\Support\Facades\Date;

function decoderIdp(): FakeAuthorizationServer
{
    return new FakeAuthorizationServer('https://idp.example.test');
}

function decoderFor(FakeAuthorizationServer $idp, int $skew = 60): OidcIdTokenDecoder
{
    return new OidcIdTokenDecoder(InMemoryJwksProvider::fromJwks($idp->jwks()), $skew);
}

it('decodes an RS256 id token against the JWKS into claims, keeping the raw value', function () {
    $idp = decoderIdp();
    $now = Date::now()->getTimestamp();
    $raw = $idp->signJwt(['iss' => 'https://idp.example.test', 'sub' => 'ada', 'aud' => ['app', 'other'], 'azp' => 'app', 'iat' => $now, 'exp' => $now + 60, 'nonce' => 'n', 'email' => 'ada@example.com']);

    $token = decoderFor($idp)->decode($raw, 'fake');

    expect($token->getTokenValue())->toBe($raw)
        ->and($token->getSubject())->toBe('ada')
        ->and($token->getIssuer())->toBe('https://idp.example.test')
        ->and($token->getAudience())->toBe(['app', 'other'])
        ->and($token->getAuthorizedParty())->toBe('app')
        ->and($token->getNonce())->toBe('n')
        ->and($token->getIssuedAt())->toBe($now)
        ->and($token->getExpiresAt())->toBe($now + 60)
        ->and($token->getClaim('email'))->toBe('ada@example.com')
        ->and($token->hasClaim('nope'))->toBeFalse()
        ->and($token->withoutTokenValue()->getTokenValue())->toBe('')
        ->and($token->withoutTokenValue()->getClaims())->toBe($token->getClaims());
});

it('refuses a foreign signature, an expired token beyond the skew, garbage, and a token without exp — each as invalid_id_token', function () {
    $idp = decoderIdp();
    $now = Date::now()->getTimestamp();
    $refused = static function (callable $attempt): string {
        try {
            $attempt();
        } catch (OAuth2AuthenticationException $e) {
            return $e->error->errorCode.': '.$e->error->description;
        }
        throw new RuntimeException('expected a refusal');
    };

    $foreign = $idp->signWithUnknownKey()->signJwt(['iss' => 'x', 'sub' => 'ada', 'exp' => $now + 60, 'iat' => $now]);
    $idp->signWithUnknownKey(false);
    $expired = $idp->signJwt(['iss' => 'x', 'sub' => 'ada', 'exp' => $now - 120, 'iat' => $now - 200]);
    $justExpired = $idp->signJwt(['iss' => 'x', 'sub' => 'ada', 'exp' => $now - 30, 'iat' => $now - 200]);
    $noExp = $idp->signJwt(['iss' => 'x', 'sub' => 'ada', 'iat' => $now]);

    expect($refused(fn () => decoderFor($idp)->decode($foreign, 'fake')))->toContain('invalid_id_token: The id token signature')
        ->and($refused(fn () => decoderFor($idp)->decode($expired, 'fake')))->toBe('invalid_id_token: The id token has expired.')
        ->and(decoderFor($idp, 60)->decode($justExpired, 'fake')->getSubject())->toBe('ada')
        ->and($refused(fn () => decoderFor($idp, 0)->decode($justExpired, 'fake')))->toContain('expired')
        ->and($refused(fn () => decoderFor($idp)->decode('not.a.jwt', 'fake')))->toContain('invalid_id_token')
        ->and($refused(fn () => decoderFor($idp)->decode($noExp, 'fake')))->toContain('invalid_id_token');
});
