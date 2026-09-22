<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Client\Tests\Support\OAuth2ClientCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;

/** A registration granted only `openid`: the person is signed in, and every scope rule refuses them. */
abstract class ScopeDeniedCapstoneTestCase extends OAuth2ClientCapstoneTestCase
{
    protected function registrationOverrides(): array
    {
        return ['scope' => ['openid']];
    }
}

uses(ScopeDeniedCapstoneTestCase::class, SecurityFlows::class);

it('refuses the URL rule and the method rule for a scope that was not granted, as 403s with the denial event', function () {
    /** @var ScopeDeniedCapstoneTestCase $this */
    $callback = $this->signInThroughProvider();
    $this->forgetSession();
    $client = $this->followSession($callback);

    $client->getJson('/whoami')->assertOk()->assertJson(['name' => 'ada', 'authorities' => ['OIDC_USER', 'SCOPE_openid']]);
    $client->getJson('/api/scoped')->assertStatus(403)->assertJson(['code' => 'ACCESS_DENIED']);
    $client->getJson('/api/email')->assertStatus(403)->assertJson(['requiredAuthorities' => ['SCOPE_email']]);
    $client->getJson('/account')->assertOk()->assertJson(['hasUserInfo' => false]);

    expect($this->events->denials())->toHaveCount(2)
        ->and($this->idp->userInfoRequests)->toBe([]);
});
