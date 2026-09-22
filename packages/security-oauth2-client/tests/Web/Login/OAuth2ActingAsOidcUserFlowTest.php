<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Client\Tests\Support\OAuth2ClientCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;

/** The acting OIDC user through the real filters: the typed principal, the URL scope rule, the method scope rule — no provider involved. */
uses(OAuth2ClientCapstoneTestCase::class, SecurityFlows::class);

it('serves a controller that needs an OidcUser and scope rules for an acting OIDC user, without any provider traffic', function () {
    /** @var OAuth2ClientCapstoneTestCase $this */
    $client = $this->actingAsOidcUser(['sub' => 'ada', 'email' => 'ada@example.com', 'groups' => ['qa']], ['ROLE_ENGINEERING'], 'fake', ['openid', 'profile']);

    $client->getJson('/account')->assertOk()->assertJson(['name' => 'ada', 'email' => 'ada@example.com', 'groups' => ['qa'], 'idTokenValue' => '', 'hasUserInfo' => false]);
    $client->getJson('/api/scoped')->assertOk();
    $client->getJson('/api/engineers')->assertOk();
    $client->getJson('/api/email')->assertStatus(403);

    expect($this->idp->discoveryRequests)->toBe(0)
        ->and($this->idp->tokenRequests)->toBe([]);
});
