<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Client\Tests\Support\OAuth2ClientCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;

/**
 * A provider spelled out explicitly (every endpoint configured, so discovery is never consulted and no
 * end_session_uri is known): `logout.oidc_initiated` degrades to the default redirect, with a warning.
 */
abstract class NoEndSessionCapstoneTestCase extends OAuth2ClientCapstoneTestCase
{
    protected function providerOverrides(): array
    {
        $issuer = self::ISSUER;

        return [
            'authorization_uri' => $issuer.'/authorize',
            'token_uri' => $issuer.'/token',
            'jwk_set_uri' => $issuer.'/jwks',
            'user_info_uri' => $issuer.'/userinfo',
        ];
    }

    protected function clientOverrides(): array
    {
        return ['firefly.security.oauth2.client.logout.oidc_initiated' => true];
    }
}

uses(NoEndSessionCapstoneTestCase::class, SecurityFlows::class);

it('falls back to logout_success_url when the provider has no end-session endpoint', function () {
    /** @var NoEndSessionCapstoneTestCase $this */
    $callback = $this->signInThroughProvider();
    expect($this->idp->discoveryRequests)->toBe(0);

    $token = $this->csrfTokenOf($callback);
    $this->forgetSession();
    $logout = $this->followSession($callback)->post('/logout', ['_token' => $token]);

    $logout->assertRedirect('/login?logout');
    expect($this->idp->endSessionRequests)->toBe([])
        ->and($this->events->logouts())->toHaveCount(1);
});
