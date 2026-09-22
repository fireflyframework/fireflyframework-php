<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\OAuth2\JwksDocumentSource;
use Firefly\Security\OAuth2\JwksProvider;
use Firefly\Security\OAuth2\JwksUri;
use Firefly\Security\OAuth2\LocalJwksProvider;
use Firefly\Security\OAuth2\RemoteJwksProvider;
use Firefly\Security\SecurityAutoConfiguration;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

/**
 * A SERVER THAT SIGNS ITS OWN TOKENS SHOULD NOT FETCH ITS OWN KEYS OVER HTTP. An application that mints
 * service tokens and serves /.well-known/jwks.json for them pointed jwks_uri back at itself so those tokens
 * would verify — and RemoteJwksProvider dutifully made a nested HTTP request to the same process on every
 * authenticated call (844 of 3,000 requests measured on one dev stack), which deadlocked a single-process
 * server. When the key set is in this process, the provider asks for it in this process.
 */
function jwksDocumentSource(): JwksDocumentSource
{
    return new class implements JwksDocumentSource
    {
        public function jwks(): array
        {
            return fakeJwksDocument('local-kid');
        }
    };
}

/**
 * @param  array<string,mixed>  $resourceServer
 */
function jwksContainer(array $resourceServer, ?JwksDocumentSource $source, string $appUrl = 'http://127.0.0.1:8080', int $port = 8080): Container
{
    $container = new Container;
    $container->instance(Cache::class, new CacheRepository(new ArrayStore));
    $container->instance(Config::class, new Config(new Repository([
        'app' => ['url' => $appUrl],
        'firefly' => [
            'server' => ['port' => $port],
            'security' => ['oauth2' => ['resource_server' => ['enabled' => true, ...$resourceServer]]],
        ],
    ])));
    if ($source !== null) {
        $container->instance(JwksDocumentSource::class, $source);
    }

    return $container;
}

function jwksBean(Container $container): JwksProvider
{
    /** @var Config $config */
    $config = $container->make(Config::class);

    return (new SecurityAutoConfiguration)->jwksProvider($config, $container);
}

it('serves the keys of an in-process document source without any network', function () {
    Http::fake(static fn () => throw new LogicException('the network must not be touched'));

    $keys = (new LocalJwksProvider(jwksDocumentSource()))->keys();

    expect($keys)->toHaveKey('local-kid');
    Http::assertNothingSent();
});

it('knows when a JWKS URI names this very application', function () {
    expect(JwksUri::isOwn('http://127.0.0.1:8080/.well-known/jwks.json', 'http://cp.example.test', 8080))->toBeTrue()
        ->and(JwksUri::isOwn('http://localhost:8080/.well-known/jwks.json', '', 8080))->toBeTrue()
        ->and(JwksUri::isOwn('https://cp.example.test/.well-known/jwks.json', 'https://cp.example.test', 0))->toBeTrue()
        ->and(JwksUri::isOwn('https://cp.example.test:443/.well-known/jwks.json', 'https://cp.example.test', 0))->toBeTrue()
        // A different host, a different port on the loopback, or a different path is somebody else's.
        ->and(JwksUri::isOwn('https://login.microsoftonline.com/common/discovery/keys', 'https://cp.example.test', 8080))->toBeFalse()
        ->and(JwksUri::isOwn('http://127.0.0.1:9090/.well-known/jwks.json', 'http://127.0.0.1:8080', 8080))->toBeFalse()
        ->and(JwksUri::isOwn('http://127.0.0.1:8080/oauth/keys', 'http://127.0.0.1:8080', 8080))->toBeFalse()
        ->and(JwksUri::isOwn('not a uri', 'http://127.0.0.1:8080', 8080))->toBeFalse();
});

it('binds the local provider when the URI is its own and a document source exists (jwks_source auto)', function () {
    $provider = jwksBean(jwksContainer(['jwks_uri' => 'http://127.0.0.1:8080/.well-known/jwks.json'], jwksDocumentSource()));

    expect($provider)->toBeInstanceOf(LocalJwksProvider::class);
});

it('keeps the remote provider for a foreign issuer even when a document source exists', function () {
    $provider = jwksBean(jwksContainer(['jwks_uri' => 'https://login.microsoftonline.com/common/discovery/keys'], jwksDocumentSource()));

    expect($provider)->toBeInstanceOf(RemoteJwksProvider::class);
});

it('keeps the remote provider for its own URI when nothing in-process can answer', function () {
    $provider = jwksBean(jwksContainer(['jwks_uri' => 'http://127.0.0.1:8080/.well-known/jwks.json'], null));

    expect($provider)->toBeInstanceOf(RemoteJwksProvider::class);
});

it('binds the local provider on jwks_source=local whatever the URI says', function () {
    $provider = jwksBean(jwksContainer(['jwks_uri' => 'https://login.microsoftonline.com/common/discovery/keys', 'jwks_source' => 'local'], jwksDocumentSource()));

    expect($provider)->toBeInstanceOf(LocalJwksProvider::class);
});

it('refuses to boot with jwks_source=local and no document source, naming the port to implement', function () {
    expect(fn () => jwksBean(jwksContainer(['jwks_uri' => 'http://127.0.0.1:8080/.well-known/jwks.json', 'jwks_source' => 'local'], null)))
        ->toThrow(ConfigurationException::class, JwksDocumentSource::class);
});

it('binds the remote provider on jwks_source=remote even when it could answer in-process', function () {
    $provider = jwksBean(jwksContainer(['jwks_uri' => 'http://127.0.0.1:8080/.well-known/jwks.json', 'jwks_source' => 'remote'], jwksDocumentSource()));

    expect($provider)->toBeInstanceOf(RemoteJwksProvider::class);
});

it('refuses an unknown jwks_source', function () {
    expect(fn () => jwksBean(jwksContainer(['jwks_uri' => 'http://127.0.0.1:8080/.well-known/jwks.json', 'jwks_source' => 'sometimes'], null)))
        ->toThrow(ConfigurationException::class, 'jwks_source');
});
