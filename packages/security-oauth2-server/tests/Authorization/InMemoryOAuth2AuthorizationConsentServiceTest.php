<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Authorization\InMemoryOAuth2AuthorizationConsentService;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationConsent;

it('stores a consent per client and principal, merges scopes on save, and removes', function () {
    $service = new InMemoryOAuth2AuthorizationConsentService;
    $consent = new OAuth2AuthorizationConsent('web-app', 'ada', ['openid']);
    $service->save($consent);
    $service->save($consent->withScopes(['openid', 'profile']));

    expect(OAuth2AuthorizationConsent::id('web-app', 'ada'))->toBe('web-app|ada')
        ->and($service->findById('web-app', 'ada')?->scopes)->toBe(['openid', 'profile'])
        ->and($service->findById('web-app', 'ada')?->hasScopes(['profile']))->toBeTrue()
        ->and($service->findById('web-app', 'ada')?->hasScopes(['email']))->toBeFalse()
        ->and($service->findById('web-app', 'root'))->toBeNull();

    $service->remove($consent);
    expect($service->findById('web-app', 'ada'))->toBeNull();
});
