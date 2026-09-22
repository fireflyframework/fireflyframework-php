<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Client\Tests\Support\OAuth2ClientCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;

/** A registration without `openid`: no nonce, no id token, no JWKS — the userinfo endpoint names the person. */
abstract class PlainOAuth2CapstoneTestCase extends OAuth2ClientCapstoneTestCase
{
    protected function registrationOverrides(): array
    {
        return ['scope' => ['profile'], 'client_name' => 'Plain OAuth2'];
    }
}

uses(PlainOAuth2CapstoneTestCase::class, SecurityFlows::class);

it('signs a plain OAuth2 user in from userinfo alone', function () {
    /** @var PlainOAuth2CapstoneTestCase $this */
    $start = $this->startLogin();
    $query = $this->queryOf($start);
    expect($query)->not->toHaveKey('nonce')
        ->and($query['scope'])->toBe('profile')
        ->and($query['code_challenge_method'] ?? null)->toBe('S256');

    $callback = $this->follow($start, $this->follow($start, $start));
    $callback->assertRedirect('/');

    $this->forgetSession();
    $whoami = $this->followSession($callback)->getJson('/whoami');
    $whoami->assertOk()->assertJson(['name' => 'ada']);
    expect($whoami->json('authorities'))->toBe(['OAUTH2_USER', 'SCOPE_profile'])
        ->and($this->idp->jwksRequests)->toBe(0)
        ->and($this->idp->userInfoRequests)->toHaveCount(1);
});
