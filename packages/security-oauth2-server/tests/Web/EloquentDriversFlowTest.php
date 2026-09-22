<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Eloquent\OAuth2ServerSchema;
use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerCapstoneTestCase;
use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerEloquentCapstoneTestCase;
use Illuminate\Support\Facades\DB;

uses(OAuth2ServerEloquentCapstoneTestCase::class);

it('runs the code flow, a refresh, introspection and revocation against the sqlite tables, with every token stored by hash', function () {
    /** @var OAuth2ServerEloquentCapstoneTestCase $this */
    $this->signIn();
    $oauth2 = $this->oauth2();

    $tokens = $oauth2->tokens('web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid profile', OAuth2ServerCapstoneTestCase::WEB_APP_SECRET);
    /** @var string $accessToken */
    $accessToken = $tokens['access_token'];
    /** @var string $refreshToken */
    $refreshToken = $tokens['refresh_token'];
    expect(DB::table(OAuth2ServerSchema::CLIENTS)->count())->toBe(3)
        ->and(DB::table(OAuth2ServerSchema::AUTHORIZATIONS)->count())->toBe(1)
        ->and(DB::table(OAuth2ServerSchema::CONSENTS)->where('id', 'web-app|ada')->value('scopes'))->toBe('openid profile');

    $rows = json_encode(DB::table(OAuth2ServerSchema::AUTHORIZATIONS)->get());
    expect($rows)->not->toContain($accessToken)->not->toContain($refreshToken);

    /** @var array<string,mixed> $renewed */
    $renewed = $oauth2->refresh('web-app', $refreshToken, OAuth2ServerCapstoneTestCase::WEB_APP_SECRET)->assertOk()->json();
    /** @var string $renewedAccess */
    $renewedAccess = $renewed['access_token'];
    $oauth2->refresh('web-app', $refreshToken, OAuth2ServerCapstoneTestCase::WEB_APP_SECRET)->assertStatus(400)->assertJson(['error' => 'invalid_grant']);
    expect(DB::table(OAuth2ServerSchema::AUTHORIZATIONS)->count())->toBe(0);

    $again = $oauth2->tokens('web-app', OAuth2ServerCapstoneTestCase::REDIRECT_URI, 'openid', OAuth2ServerCapstoneTestCase::WEB_APP_SECRET);
    /** @var string $againAccess */
    $againAccess = $again['access_token'];
    /** @var string $againRefresh */
    $againRefresh = $again['refresh_token'];
    $oauth2->introspect('svc', OAuth2ServerCapstoneTestCase::SVC_SECRET, $againAccess)->assertJson(['active' => true, 'sub' => 'ada']);
    $oauth2->revoke('web-app', OAuth2ServerCapstoneTestCase::WEB_APP_SECRET, $againRefresh)->assertOk();
    $oauth2->introspect('svc', OAuth2ServerCapstoneTestCase::SVC_SECRET, $againAccess)->assertJson(['active' => false]);
    // The reuse detection removed the authorization $renewed belonged to: its access token resolves to nothing.
    $oauth2->introspect('svc', OAuth2ServerCapstoneTestCase::SVC_SECRET, $renewedAccess)->assertJson(['active' => false]);
});
