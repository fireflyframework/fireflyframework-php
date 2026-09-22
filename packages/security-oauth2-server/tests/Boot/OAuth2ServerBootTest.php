<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use Firefly\Security\OAuth2\Server\Tests\Support\OAuth2ServerBootTestCase;

uses(OAuth2ServerBootTestCase::class);

it('boots the server over session security and binds the settings bean from the configuration', function () {
    /** @var OAuth2ServerBootTestCase $this */
    $settings = $this->app()->make(AuthorizationServerSettings::class);

    expect($settings)->toBeInstanceOf(AuthorizationServerSettings::class)
        ->and($settings->enabled)->toBeTrue()
        ->and($settings->issuer)->toBe('http://localhost');
});
