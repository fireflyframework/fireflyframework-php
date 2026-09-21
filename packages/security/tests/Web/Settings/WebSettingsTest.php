<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Security\Jwt\WeakSigningSecretException;
use Firefly\Security\Web\Settings\FormLoginSettings;
use Firefly\Security\Web\Settings\HttpBasicSettings;
use Firefly\Security\Web\Settings\LogoutSettings;
use Firefly\Security\Web\Settings\RememberMeSettings;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;

/** @param array<string, mixed> $security */
function securityConfig(array $security): Config
{
    return new Config(new Repository(['firefly' => ['security' => $security]]));
}

it('reads the form-login defaults and recognises its two URLs by path only', function () {
    $settings = FormLoginSettings::fromConfig(securityConfig(['form_login' => ['enabled' => true]]));

    expect($settings->loginPage)->toBe('/login')
        ->and($settings->loginProcessingUrl)->toBe('/login')
        ->and($settings->usernameParameter)->toBe('username')
        ->and($settings->passwordParameter)->toBe('password')
        ->and($settings->defaultSuccessUrl)->toBe('/')
        ->and($settings->alwaysUseDefaultSuccessUrl)->toBeFalse()
        ->and($settings->failureUrl)->toBe('/login?error')
        ->and($settings->view)->toBeNull()
        ->and($settings->isLoginPage(Request::create('/login?error', 'GET')))->toBeTrue()
        ->and($settings->isLoginPage(Request::create('/login', 'POST')))->toBeFalse()
        ->and($settings->isLoginProcessing(Request::create('/login', 'POST')))->toBeTrue()
        ->and($settings->isLoginProcessing(Request::create('/login/', 'POST')))->toBeTrue()
        ->and($settings->isLoginProcessing(Request::create('/signin', 'POST')))->toBeFalse()
        ->and(FormLoginSettings::fromConfig(securityConfig([]))->isLoginPage(Request::create('/login', 'GET')))->toBeFalse();
});

it('reads the basic, logout and remember-me settings with their documented defaults', function () {
    $basic = HttpBasicSettings::fromConfig(securityConfig(['http_basic' => ['enabled' => true, 'realm' => 'Ledger "v2"']]));
    $logout = LogoutSettings::fromConfig(securityConfig(['form_login' => ['enabled' => true]]));
    $remember = RememberMeSettings::fromConfig(securityConfig(['remember_me' => ['enabled' => true, 'key' => str_repeat('r', 40)]]));

    expect($basic->realm)->toBe('Ledger "v2"')
        ->and($basic->session)->toBeFalse()
        ->and($basic->challenge())->toBe('Basic realm="Ledger \"v2\"", charset="UTF-8"')
        ->and($logout->enabled)->toBeTrue() // follows form_login.enabled
        ->and($logout->logoutUrl)->toBe('/logout')
        ->and($logout->logoutSuccessUrl)->toBe('/login?logout')
        ->and($logout->invalidateSession)->toBeTrue()
        ->and($logout->deleteCookies)->toBe([])
        ->and($logout->clearAuthentication)->toBeTrue()
        ->and($logout->isLogout(Request::create('/logout', 'POST')))->toBeTrue()
        ->and($logout->isLogout(Request::create('/logout', 'GET')))->toBeFalse()
        ->and(LogoutSettings::fromConfig(securityConfig([]))->enabled)->toBeFalse()
        ->and($remember->parameter)->toBe('remember-me')
        ->and($remember->cookieName)->toBe('remember-me')
        ->and($remember->tokenValiditySeconds)->toBe(1209600)
        ->and($remember->alwaysRemember)->toBeFalse();
});

it('refuses a weak remember-me key the way JwtService refuses a weak secret, and only when enabled', function () {
    expect(fn () => RememberMeSettings::fromConfig(securityConfig(['remember_me' => ['enabled' => true, 'key' => 'changeme']])))->toThrow(WeakSigningSecretException::class)
        ->and(fn () => RememberMeSettings::fromConfig(securityConfig(['remember_me' => ['enabled' => true, 'key' => str_repeat('k', 31)]])))->toThrow(WeakSigningSecretException::class)
        ->and(RememberMeSettings::fromConfig(securityConfig(['remember_me' => ['key' => 'changeme']]))->enabled)->toBeFalse();
});

it('mounts the login page for OAuth2 login alone, and turns logout on with it', function () {
    $oauth2Only = FormLoginSettings::fromConfig(securityConfig(['oauth2' => ['client' => ['login' => ['enabled' => true]]]]));
    $form = FormLoginSettings::fromConfig(securityConfig(['form_login' => ['enabled' => true]]));

    expect($oauth2Only->enabled)->toBeFalse()
        ->and($oauth2Only->pageEnabled)->toBeTrue()
        ->and($oauth2Only->isLoginPage(Request::create('/login', 'GET')))->toBeTrue()
        ->and($oauth2Only->isLoginProcessing(Request::create('/login', 'POST')))->toBeFalse()
        ->and($form->pageEnabled)->toBeTrue()
        ->and((new FormLoginSettings(enabled: true))->pageEnabled)->toBeTrue()
        ->and((new FormLoginSettings)->pageEnabled)->toBeFalse()
        ->and(LogoutSettings::fromConfig(securityConfig(['oauth2' => ['client' => ['login' => ['enabled' => true]]]]))->enabled)->toBeTrue();
});
