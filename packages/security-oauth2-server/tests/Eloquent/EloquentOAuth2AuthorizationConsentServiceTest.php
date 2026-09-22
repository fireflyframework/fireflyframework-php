<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationConsent;
use Firefly\Security\OAuth2\Server\Eloquent\EloquentOAuth2AuthorizationConsentService;
use Firefly\Security\OAuth2\Server\Eloquent\OAuth2AuthorizationConsentModelRepository;
use Firefly\Security\OAuth2\Server\Eloquent\OAuth2ServerSchema;
use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerSqliteTestCase;
use Illuminate\Support\Facades\DB;

uses(OAuth2ServerSqliteTestCase::class);

it('round-trips a consent per client and principal', function () {
    $service = new EloquentOAuth2AuthorizationConsentService(new OAuth2AuthorizationConsentModelRepository);
    $service->save(new OAuth2AuthorizationConsent('web-app', 'ada', ['openid']));
    $service->save(new OAuth2AuthorizationConsent('web-app', 'ada', ['openid', 'profile']));

    expect(DB::table(OAuth2ServerSchema::CONSENTS)->count())->toBe(1)
        ->and($service->findById('web-app', 'ada')?->scopes)->toBe(['openid', 'profile'])
        ->and($service->findById('web-app', 'root'))->toBeNull();

    $service->remove(new OAuth2AuthorizationConsent('web-app', 'ada', []));
    expect($service->findById('web-app', 'ada'))->toBeNull();
});
