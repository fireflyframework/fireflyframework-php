<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Client\Tests\Support\OAuth2ClientCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;

/**
 * The principal after an OIDC login, as a controller sees it on the NEXT request — read back from the session:
 * an OidcUser with the merged claims, no raw id token, the userinfo, and `SCOPE_x` honoured by a URL rule and a
 * method rule.
 */
uses(OAuth2ClientCapstoneTestCase::class, SecurityFlows::class);

it('injects the OidcUser into a controller with its claims, and honours hasScope in a URL rule and a method rule', function () {
    /** @var OAuth2ClientCapstoneTestCase $this */
    $callback = $this->signInThroughProvider();
    $this->forgetSession();
    $client = $this->followSession($callback);

    $account = $client->getJson('/account');
    $account->assertOk()->assertJson([
        'name' => 'ada',
        'subject' => 'ada',
        'email' => 'ada@example.com',
        'fullName' => 'Ada Lovelace',
        'groups' => ['engineering'],
        'idTokenValue' => '',
        'hasUserInfo' => true,
        'issuer' => OAuth2ClientCapstoneTestCase::ISSUER,
    ]);

    $client->getJson('/account/any')->assertOk()->assertJson(['name' => 'ada', 'kind' => 'oidc']);
    $client->getJson('/api/scoped')->assertOk()->assertJson(['scoped' => true]);
    $client->getJson('/api/email')->assertOk()->assertJson(['email' => true]);
    $client->getJson('/api/engineers')->assertStatus(403);

    expect($this->events->denials())->toHaveCount(1);
});

it('answers 401 for the typed principal when nobody is signed in, and null for the nullable one on an open path', function () {
    /** @var OAuth2ClientCapstoneTestCase $this */
    $this->getJson('/account')->assertStatus(401);
    $this->actingAsPrincipal('ada', ['ROLE_USER'])->getJson('/account')->assertStatus(401)->assertJson(['code' => 'AUTHENTICATION_FAILED']);
    $this->actingAsPrincipal('ada', ['ROLE_USER'])->getJson('/account/any')->assertOk()->assertJson(['name' => null, 'kind' => 'none']);
});
