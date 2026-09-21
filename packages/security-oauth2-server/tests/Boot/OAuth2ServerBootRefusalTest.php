<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\OAuth2\Server\Boot\OAuth2ServerWiringPass;
use Firefly\Security\OAuth2\Server\Jose\KeyPairGenerator;
use Firefly\Security\OAuth2\Server\SecurityOAuth2ServerServiceProvider;
use Firefly\Security\OAuth2\Server\SecurityOAuth2ServerWiringProvider;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use Firefly\Security\OAuth2\Server\Web\OAuth2AuthorizationServerFilter;
use Firefly\Security\SecurityServiceProvider;
use Firefly\Security\SecurityWiringProvider;
use Firefly\Security\Web\Basic\HttpBasicFilter;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\WebServiceProvider;
use Illuminate\Config\Repository;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Foundation\Application;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as IlluminateFactory;

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

/**
 * The same boot WITH the web provider (the recipe of packages/security/tests/Web/EntryPoint/EntryPointFlowTest.php),
 * for a refusal that only an eligible web filter can reach: HttpBasicFilter takes the BasicAuthenticationEntryPoint,
 * whose renderers need the view factory, so under `http_basic.enabled` the EagerSingletonsPass (phase 900) dies on
 * [view] in the bare boot above before the wiring pass (phase 1000) could refuse. A signing key is supplied so the
 * eager pass, which resolves JwtSigningKeys ahead of the wiring pass too, does not refuse over that first.
 *
 * @param  array<string,mixed>  $security  the `firefly.security.*` tree for this boot, minus the server block
 */
function bootOAuth2ServerWebAppWith(array $security): Application
{
    return fireflyApplication(
        config: ['app' => ['url' => 'http://localhost'], 'session' => ['driver' => 'array'], 'firefly' => ['cqrs' => [], 'security' => $security + [
            'oauth2' => ['server' => ['enabled' => true, 'jwt' => ['signing_key' => KeyPairGenerator::generate('RS256')]]],
        ]]],
        providers: [ValidationServiceProvider::class, WebServiceProvider::class, CqrsServiceProvider::class, CqrsWiringProvider::class, SecurityServiceProvider::class, SecurityWiringProvider::class, SecurityOAuth2ServerServiceProvider::class, SecurityOAuth2ServerWiringProvider::class],
        bindings: [Factory::class => new IlluminateFactory(new Translator(new ArrayLoader, 'en'))],
        needs: ['cache', 'http'],
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

it('refuses the server beside HTTP Basic, naming both keys — the -91 filter would answer every client_secret_basic request as a failed user login', function (array $security) {
    /** @var array<string, mixed> $security */
    try {
        OAuth2ServerWiringPass::assertRunnable(oauth2ServerConfig(['enabled' => true, 'oauth2' => ['server' => ['enabled' => true]]] + $security));
        throw new LogicException('not refused');
    } catch (ConfigurationException $e) {
        expect($e->getMessage())->toContain('firefly.security.http_basic.enabled')
            ->toContain('firefly.security.oauth2.server.enabled')
            ->toContain('HttpBasicFilter (-91)')
            ->toContain('client_secret_basic');
    }
})->with([
    'basic beside form login' => [['form_login' => ['enabled' => true], 'http_basic' => ['enabled' => true]]],
    'basic with a session, as the only session source' => [['http_basic' => ['enabled' => true, 'session' => true]]],
]);

it('refuses the server beside HTTP Basic through the real web boot — the -91 filter is constructed, then the wiring pass refuses — and boots the same recipe with Basic off', function () {
    expect(fn () => bootOAuth2ServerWebAppWith(['enabled' => true, 'form_login' => ['enabled' => true], 'http_basic' => ['enabled' => true]]))
        ->toThrow(ConfigurationException::class, 'firefly.security.http_basic.enabled')
        ->and(fn () => bootOAuth2ServerWebAppWith(['enabled' => true, 'http_basic' => ['enabled' => true, 'session' => true]]))
        ->toThrow(ConfigurationException::class, 'firefly.security.http_basic.enabled');

    /** @var ApplicationContext $context */
    $context = bootOAuth2ServerWebAppWith(['enabled' => true, 'form_login' => ['enabled' => true]])->make(ApplicationContext::class);

    expect($context->has(OAuth2AuthorizationServerFilter::class))->toBeTrue()
        ->and($context->has(HttpBasicFilter::class))->toBeFalse();
});

it('accepts the server over form login, over session.enabled and over remember-me', function (array $security) {
    /** @var array<string, mixed> $security */
    OAuth2ServerWiringPass::assertRunnable(oauth2ServerConfig(['enabled' => true, 'oauth2' => ['server' => ['enabled' => true]]] + $security));

    expect(true)->toBeTrue();
})->with([
    'form login' => [['form_login' => ['enabled' => true]]],
    'session' => [['session' => ['enabled' => true]]],
    'remember-me' => [['remember_me' => ['enabled' => true]]],
]);

it('refuses two published keys under one kid at boot — the rotation that kept jwt.key_id — rather than on the first token, through the real boot', function () {
    expect(fn () => bootOAuth2ServerAppWith([
        'enabled' => true,
        'form_login' => ['enabled' => true],
        'oauth2' => ['server' => ['enabled' => true, 'jwt' => [
            'algorithm' => 'ES256',
            'signing_key' => KeyPairGenerator::generate('ES256'),
            'key_id' => 'main',
            'previous_keys' => [['key' => KeyPairGenerator::generate('ES256'), 'key_id' => 'main']],
        ]]],
    ]))->toThrow(ConfigurationException::class, 'firefly.security.oauth2.server.jwt.previous_keys[0] and jwt.signing_key are different keys published under the same kid `main`');
});

it('refuses a client block that could never authenticate at boot, naming the client, rather than on the first token request, through the real boot', function () {
    expect(fn () => bootOAuth2ServerAppWith([
        'enabled' => true,
        'form_login' => ['enabled' => true],
        'oauth2' => ['server' => [
            'enabled' => true,
            'jwt' => ['signing_key' => KeyPairGenerator::generate('RS256')],
            'clients' => ['driver' => 'memory', 'web-app' => ['client_secret' => 'plain', 'redirect_uris' => ['https://app.test/cb']]],
        ]],
    ]))->toThrow(ConfigurationException::class, 'Client [web-app]: client_secret must be an encoded value with an {id} prefix');
});
