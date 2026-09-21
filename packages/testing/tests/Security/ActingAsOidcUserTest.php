<?php

declare(strict_types=1);

use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\OAuth2\Client\User\OAuth2AuthenticationToken;
use Firefly\Security\OAuth2\Client\User\OidcUser;
use Firefly\Security\OAuth2\Client\User\OidcUserAuthority;
use Firefly\Testing\FireflyTestCase;
use Illuminate\Support\Facades\Route;

uses(FireflyTestCase::class);

afterEach(fn () => SecurityContextHolder::clearContext());

it('acts as an OIDC user: an OidcUser principal with the claims, OIDC_USER + SCOPE_x + the extra authorities, the registration id', function () {
    /** @var FireflyTestCase $this */
    Route::get('/acting-oidc', static function (): array {
        $authentication = SecurityContextHolder::getAuthentication();
        $user = OAuth2AuthenticationToken::principal($authentication);

        return [
            'name' => $authentication?->getName(),
            'email' => $user instanceof OidcUser ? $user->getEmail() : null,
            'authorities' => $authentication?->authorityStrings(),
            'registration' => OAuth2AuthenticationToken::registrationId($authentication),
        ];
    });

    $this->actingAsOidcUser(['sub' => 'u-1', 'email' => 'ada@example.com', 'preferred_username' => 'ada'], ['ROLE_ADMIN'], 'okta', ['openid', 'email'], 'preferred_username');

    $authentication = SecurityContextHolder::getAuthentication();
    $user = OAuth2AuthenticationToken::principal($authentication);
    expect($authentication?->getName())->toBe('ada')
        ->and($user)->toBeInstanceOf(OidcUser::class)
        ->and($user instanceof OidcUser ? $user->getSubject() : null)->toBe('u-1')
        ->and($user instanceof OidcUser ? $user->getIdToken()->getTokenValue() : null)->toBe('')
        ->and($user instanceof OidcUser ? $user->getClaim('iss') : null)->toBe('https://idp.test')
        ->and($user instanceof OidcUser ? $user->getClaim('aud') : null)->toBe('firefly-app')
        ->and($user instanceof OidcUser ? $user->hasClaim('exp') : false)->toBeTrue()
        ->and($user instanceof OidcUser ? $user->getAuthorities()[0] : null)->toBeInstanceOf(OidcUserAuthority::class)
        ->and(array_map(static fn ($a): string => $a->getAuthority(), $user instanceof OidcUser ? $user->getAuthorities() : []))->toBe(['OIDC_USER', 'SCOPE_openid', 'SCOPE_email'])
        ->and($authentication?->authorityStrings())->toBe(['OIDC_USER', 'SCOPE_openid', 'SCOPE_email', 'ROLE_ADMIN'])
        ->and(OAuth2AuthenticationToken::registrationId($authentication))->toBe('okta');

    $this->getJson('/acting-oidc')->assertJson(['name' => 'ada', 'email' => 'ada@example.com', 'authorities' => ['OIDC_USER', 'SCOPE_openid', 'SCOPE_email', 'ROLE_ADMIN'], 'registration' => 'okta']);
});

it('defaults to a user named `user` from a provider named `oidc` with the openid scope, and actingAsPrincipal still delegates to the seam', function () {
    /** @var FireflyTestCase $this */
    $this->actingAsOidcUser();
    $authentication = SecurityContextHolder::getAuthentication();

    expect($authentication?->getName())->toBe('user')
        ->and($authentication?->authorityStrings())->toBe(['OIDC_USER', 'SCOPE_openid'])
        ->and(OAuth2AuthenticationToken::registrationId($authentication))->toBe('oidc');

    $this->actingAsAuthentication(Authentication::authenticated('svc', 'svc', [], ['tenant' => 't-1']));
    expect(SecurityContextHolder::getAuthentication()?->getAttributes())->toBe(['tenant' => 't-1']);

    $this->actingAsPrincipal('ada', ['ROLE_USER']);
    expect(SecurityContextHolder::getAuthentication()?->getName())->toBe('ada');
});
