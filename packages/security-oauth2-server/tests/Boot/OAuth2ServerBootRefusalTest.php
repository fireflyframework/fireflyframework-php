<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\OAuth2\Server\Boot\OAuth2ServerWiringPass;
use Firefly\Security\OAuth2\Server\SecurityOAuth2ServerServiceProvider;
use Firefly\Security\OAuth2\Server\SecurityOAuth2ServerWiringProvider;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use Firefly\Security\SecurityServiceProvider;
use Firefly\Security\SecurityWiringProvider;
use Illuminate\Config\Repository;
use Illuminate\Foundation\Application;

/**
 * The cqrs providers come along because security's master-gated beans consume the HandlerManifest (see
 * packages/security/tests/RealProviderBootTest.php); `needs: ['cache']` satisfies the jwksProvider bean.
 *
 * @param  array<string,mixed>  $security  the `firefly.security.*` tree for this boot
 */
function bootOAuth2ServerAppWith(array $security): Application
{
    return fireflyApplication(
        config: ['app' => ['url' => 'http://localhost'], 'firefly' => ['cqrs' => [], 'security' => $security]],
        providers: [CqrsServiceProvider::class, CqrsWiringProvider::class, SecurityServiceProvider::class, SecurityWiringProvider::class, SecurityOAuth2ServerServiceProvider::class, SecurityOAuth2ServerWiringProvider::class],
        needs: ['cache'],
    );
}

/** @param array<string,mixed> $security */
function oauth2ServerConfig(array $security): Config
{
    return new Config(new Repository(['app' => ['url' => 'http://localhost'], 'firefly' => ['security' => $security]]));
}

it('is inert by default: security on, the server off, no settings bean and no refusal', function () {
    $app = bootOAuth2ServerAppWith(['enabled' => true]);

    expect($app->bound(AuthorizationServerSettings::class))->toBeFalse();
});

it('refuses the server without the master flag, through the real boot', function () {
    expect(fn () => bootOAuth2ServerAppWith(['enabled' => false, 'oauth2' => ['server' => ['enabled' => true]]]))
        ->toThrow(ConfigurationException::class, 'firefly.security.enabled');
});

it('refuses a settings typo at boot rather than on the first token request, through the real boot', function () {
    expect(fn () => bootOAuth2ServerAppWith([
        'enabled' => true,
        'form_login' => ['enabled' => true],
        'oauth2' => ['server' => ['enabled' => true, 'rate_limit' => ['refill_rate' => 'fast']]],
    ]))->toThrow(ConfigurationException::class, 'firefly.security.oauth2.server.rate_limit.refill_rate');
});

it('refuses the server without a signing key at boot, naming the command that generates one, through the real boot', function () {
    expect(fn () => bootOAuth2ServerAppWith([
        'enabled' => true,
        'form_login' => ['enabled' => true],
        'oauth2' => ['server' => ['enabled' => true]],
    ]))->toThrow(ConfigurationException::class, 'firefly:oauth2:keys');
});

it('says nothing while the server is off, whatever else is set', function () {
    OAuth2ServerWiringPass::assertRunnable(oauth2ServerConfig(['enabled' => false, 'jwt' => ['enabled' => true]]));

    expect(true)->toBeTrue();
});

it('refuses the server without session security, naming the keys that turn it on', function () {
    expect(fn () => OAuth2ServerWiringPass::assertRunnable(oauth2ServerConfig(['enabled' => true, 'oauth2' => ['server' => ['enabled' => true]]])))
        ->toThrow(ConfigurationException::class, 'firefly.security.form_login.enabled');
});

it('refuses the server beside the local HMAC JWT filter', function () {
    expect(fn () => OAuth2ServerWiringPass::assertRunnable(oauth2ServerConfig([
        'enabled' => true,
        'session' => ['enabled' => true],
        'jwt' => ['enabled' => true, 'secret' => str_repeat('k', 40)],
        'oauth2' => ['server' => ['enabled' => true]],
    ])))->toThrow(ConfigurationException::class, 'firefly.security.jwt.enabled');
});

it('accepts the server over form login, over session.enabled, over remember-me and over Basic with a session', function (array $security) {
    /** @var array<string, mixed> $security */
    OAuth2ServerWiringPass::assertRunnable(oauth2ServerConfig(['enabled' => true, 'oauth2' => ['server' => ['enabled' => true]]] + $security));

    expect(true)->toBeTrue();
})->with([
    'form login' => [['form_login' => ['enabled' => true]]],
    'session' => [['session' => ['enabled' => true]]],
    'remember-me' => [['remember_me' => ['enabled' => true]]],
    'basic with session' => [['http_basic' => ['enabled' => true, 'session' => true]]],
]);
