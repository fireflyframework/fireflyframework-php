<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\OAuth2\Server\Client\AuthorizationGrantType;
use Firefly\Security\OAuth2\Server\Client\InMemoryRegisteredClientRepository;
use Firefly\Security\OAuth2\Server\Client\RegisteredClient;
use Firefly\Security\OAuth2\Server\Client\RegisteredClientFactory;
use Firefly\Security\OAuth2\Server\Client\RegisteredClientRepository;
use Firefly\Security\OAuth2\Server\OpenApi\AuthorizationServerSchemeContributor;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use Firefly\Security\OAuth2\Server\Web\Consent\ConsentPage;

/**
 * The one security fact `firefly.security.*` cannot supply, asserted on its own: this application IS the
 * authorization server, so it has real flow URLs and a real scope vocabulary to publish, and the scheme it
 * contributes is the only `type: oauth2` entry this framework ever emits.
 *
 * The flows object is compared WHOLE rather than field by field, which is what pins the scope ORDER: PHP's
 * `===` over arrays is order-sensitive, so a contributor that stopped sorting would fail here rather than
 * produce a document that reshuffles itself with the client store's iteration order.
 *
 * The clients are built through RegisteredClientFactory — the same path the `memory` driver takes — rather
 * than by calling RegisteredClient's constructor, so a client this test describes is a client the server
 * would actually accept: the factory's consistency rules (an authorization_code client needs a redirect URI,
 * a secret needs its `{id}` prefix) hold for the fixtures too.
 *
 * The helper is named for this file rather than `registeredClient()`: the suite runs every package's tests
 * in one process and a bare name is a fatal redeclaration waiting for the next author who wants it.
 *
 * @param  list<string>  $scopes
 * @param  list<AuthorizationGrantType>  $grants
 */
function authorizationServerSchemeClient(string $clientId, array $scopes, array $grants = [AuthorizationGrantType::AuthorizationCode]): RegisteredClient
{
    return RegisteredClientFactory::fromConfig($clientId, [
        'client_secret' => '{noop}s',
        'redirect_uris' => ['https://'.$clientId.'.test/cb'],
        'scopes' => $scopes,
        'authorization_grant_types' => array_map(static fn (AuthorizationGrantType $grant): string => $grant->value, $grants),
    ], new AuthorizationServerSettings);
}

it('emits an authorizationCode flow from the server settings and the registered clients', function (): void {
    $settings = new AuthorizationServerSettings(
        enabled: true,
        issuer: 'https://auth.example.com',
        authorizationEndpoint: '/oauth2/authorize',
        tokenEndpoint: '/oauth2/token',
    );

    $clients = new InMemoryRegisteredClientRepository([
        authorizationServerSchemeClient('console', ['openid', 'profile', 'orders.read']),
        authorizationServerSchemeClient('mobile', ['openid', 'orders.write']),
    ]);

    $schemes = (new AuthorizationServerSchemeContributor($settings, $clients))->schemes();

    expect($schemes)->toHaveCount(1)
        ->and($schemes[0]->name)->toBe('oauth2AuthorizationCode')
        ->and($schemes[0]->definition['type'])->toBe('oauth2')
        ->and($schemes[0]->definition['description'])->toBe('This application is the authorization server: https://auth.example.com')
        ->and($schemes[0]->definition['flows'])->toBe([
            'authorizationCode' => [
                'authorizationUrl' => 'https://auth.example.com/oauth2/authorize',
                'tokenUrl' => 'https://auth.example.com/oauth2/token',
                // Sorted, and described with the consent page's own words for the scopes this server defines.
                'scopes' => [
                    'openid' => ConsentPage::describe('openid'),
                    'orders.read' => ConsentPage::describe('orders.read'),
                    'orders.write' => ConsentPage::describe('orders.write'),
                    'profile' => ConsentPage::describe('profile'),
                ],
            ],
        ]);
});

it('describes the three OIDC scopes it defines in the consent page\'s own words, and echoes every other scope name', function (): void {
    $settings = new AuthorizationServerSettings(enabled: true, issuer: 'https://auth.example.com');

    $clients = new InMemoryRegisteredClientRepository([
        authorizationServerSchemeClient('console', ['openid', 'profile', 'email', 'orders.read']),
    ]);

    expect((new AuthorizationServerSchemeContributor($settings, $clients))->schemes()[0]->definition['flows'])->toBe([
        'authorizationCode' => [
            'authorizationUrl' => 'https://auth.example.com/oauth2/authorize',
            'tokenUrl' => 'https://auth.example.com/oauth2/token',
            'scopes' => [
                'email' => 'Read your email address',
                'openid' => 'Sign you in and know who you are',
                // An application's own vocabulary gets its own name back, never invented prose.
                'orders.read' => 'Access orders.read',
                'profile' => 'Read your profile',
            ],
        ],
    ]);
});

it('leaves the scheme with no default scopes, so a requirement naming it keeps its own', function (): void {
    $settings = new AuthorizationServerSettings(enabled: true, issuer: 'https://auth.example.com');

    $clients = new InMemoryRegisteredClientRepository([
        authorizationServerSchemeClient('console', ['openid', 'orders.read']),
    ]);

    expect((new AuthorizationServerSchemeContributor($settings, $clients))->schemes()[0]->scopes)->toBe([]);
});

it('publishes no refreshUrl when no client may refresh', function (): void {
    $settings = new AuthorizationServerSettings(enabled: true, issuer: 'https://auth.example.com');

    $clients = new InMemoryRegisteredClientRepository([
        authorizationServerSchemeClient('console', ['openid']),
    ]);

    expect((new AuthorizationServerSchemeContributor($settings, $clients))->schemes()[0]->definition['flows'])->toBe([
        'authorizationCode' => [
            'authorizationUrl' => 'https://auth.example.com/oauth2/authorize',
            'tokenUrl' => 'https://auth.example.com/oauth2/token',
            'scopes' => ['openid' => ConsentPage::describe('openid')],
        ],
    ]);
});

it('publishes the token endpoint as the refreshUrl when a client may refresh, whatever the issuer\'s trailing slash', function (): void {
    $settings = new AuthorizationServerSettings(enabled: true, issuer: 'https://auth.example.com/');

    $clients = new InMemoryRegisteredClientRepository([
        authorizationServerSchemeClient('console', ['openid']),
        authorizationServerSchemeClient('mobile', ['openid'], [AuthorizationGrantType::AuthorizationCode, AuthorizationGrantType::RefreshToken]),
    ]);

    expect((new AuthorizationServerSchemeContributor($settings, $clients))->schemes()[0]->definition['flows'])->toBe([
        'authorizationCode' => [
            'authorizationUrl' => 'https://auth.example.com/oauth2/authorize',
            'tokenUrl' => 'https://auth.example.com/oauth2/token',
            'scopes' => ['openid' => ConsentPage::describe('openid')],
            'refreshUrl' => 'https://auth.example.com/oauth2/token',
        ],
    ]);
});

it('emits nothing when the server is disabled', function (): void {
    expect((new AuthorizationServerSchemeContributor(new AuthorizationServerSettings(enabled: false), new InMemoryRegisteredClientRepository([])))->schemes())->toBe([]);
});

it('still publishes the flow with an EMPTY scopes map when no registered client supports the authorization_code grant', function (): void {
    // The flow URLs are facts about the SERVER, not about its client registry, and
    // MethodSecurityRequirementContributor names `oauth2AuthorizationCode` from the very same
    // `firefly.security.oauth2.server.enabled`. Emitting nothing here would leave every method-secured
    // operation of a client_credentials-only issuer — or of a deployment whose `eloquent` client table is
    // empty when CI generates the document — naming a scheme `components.securitySchemes` does not contain.
    $settings = new AuthorizationServerSettings(enabled: true, issuer: 'https://auth.example.com');

    $clients = new InMemoryRegisteredClientRepository([
        authorizationServerSchemeClient('worker', ['orders.read'], [AuthorizationGrantType::ClientCredentials]),
    ]);

    $schemes = (new AuthorizationServerSchemeContributor($settings, $clients))->schemes();

    expect($schemes)->toHaveCount(1)
        ->and($schemes[0]->name)->toBe('oauth2AuthorizationCode')
        ->and($schemes[0]->definition['flows'])->toBe([
            'authorizationCode' => [
                'authorizationUrl' => 'https://auth.example.com/oauth2/authorize',
                'tokenUrl' => 'https://auth.example.com/oauth2/token',
                // A client_credentials client's scopes belong to a flow this scheme does not describe.
                'scopes' => [],
            ],
        ]);
});

it('publishes the flow with an empty scopes map for a server with no registered client at all', function (): void {
    $settings = new AuthorizationServerSettings(enabled: true, issuer: 'https://auth.example.com');

    $schemes = (new AuthorizationServerSchemeContributor($settings, new InMemoryRegisteredClientRepository([])))->schemes();

    expect($schemes)->toHaveCount(1)
        ->and($schemes[0]->definition['flows'])->toBe([
            'authorizationCode' => [
                'authorizationUrl' => 'https://auth.example.com/oauth2/authorize',
                'tokenUrl' => 'https://auth.example.com/oauth2/token',
                'scopes' => [],
            ],
        ]);
});

it('collects scopes only from the clients that may use the authorizationCode flow', function (): void {
    $settings = new AuthorizationServerSettings(enabled: true, issuer: 'https://auth.example.com');

    $clients = new InMemoryRegisteredClientRepository([
        authorizationServerSchemeClient('console', ['openid']),
        authorizationServerSchemeClient('worker', ['batch.run'], [AuthorizationGrantType::ClientCredentials]),
    ]);

    expect((new AuthorizationServerSchemeContributor($settings, $clients))->schemes()[0]->definition['flows'])->toBe([
        'authorizationCode' => [
            'authorizationUrl' => 'https://auth.example.com/oauth2/authorize',
            'tokenUrl' => 'https://auth.example.com/oauth2/token',
            'scopes' => ['openid' => ConsentPage::describe('openid')],
        ],
    ]);
});

it('still publishes the flow when the client store cannot be read at all', function (): void {
    // THE CASE THAT USED TO TAKE THE WHOLE DOCUMENT DOWN. `clients.driver: eloquent` makes all() a SELECT,
    // and the two documented ways of producing a document are the two places that SELECT is least likely to
    // succeed: `php artisan firefly:openapi` in a CI container whose oauth2_registered_clients table was
    // never migrated, and a /openapi.json scrape from a build step with no database behind it. Nothing on
    // the path from here to OpenApiGenerator catches, so the driver's exception used to fail the command
    // outright and answer 500 on the route — for a document that, before this contributor existed, touched
    // no database at all.
    $settings = new AuthorizationServerSettings(enabled: true, issuer: 'https://auth.example.com');

    $unreachable = new class implements RegisteredClientRepository
    {
        public function findById(string $id): ?RegisteredClient
        {
            throw new RuntimeException('SQLSTATE[42S02]: Base table or view not found: oauth2_registered_clients');
        }

        public function findByClientId(string $clientId): ?RegisteredClient
        {
            throw new RuntimeException('SQLSTATE[42S02]: Base table or view not found: oauth2_registered_clients');
        }

        public function save(RegisteredClient $client): void
        {
            throw new RuntimeException('SQLSTATE[42S02]: Base table or view not found: oauth2_registered_clients');
        }

        /** @return list<RegisteredClient> */
        public function all(): array
        {
            throw new RuntimeException('SQLSTATE[42S02]: Base table or view not found: oauth2_registered_clients');
        }
    };

    $schemes = (new AuthorizationServerSchemeContributor($settings, $unreachable))->schemes();

    // An unreadable registry lands on the answer an EMPTY one already gets: the flow URLs, which are facts
    // about the server, and the empty scopes map that says no client has registered one here yet.
    expect($schemes)->toHaveCount(1)
        ->and($schemes[0]->name)->toBe('oauth2AuthorizationCode')
        ->and($schemes[0]->definition['flows'])->toBe([
            'authorizationCode' => [
                'authorizationUrl' => 'https://auth.example.com/oauth2/authorize',
                'tokenUrl' => 'https://auth.example.com/oauth2/token',
                'scopes' => [],
            ],
        ]);
});

it('publishes the flow when a stored client is REFUSED on the way out, rather than failing the document', function (): void {
    // The `eloquent` driver validates every row it maps — an unknown grant, a plain-text secret, a relative
    // redirect URI is refused where it is read, naming the client. That refusal protects the authorization
    // endpoint, which reads the same store through findByClientId(); it must not also mean that a hand-edited
    // row can stop the API reference from being generated. The document is a report, not an admission gate.
    $settings = new AuthorizationServerSettings(enabled: true, issuer: 'https://auth.example.com');

    $refusing = new class implements RegisteredClientRepository
    {
        public function findById(string $id): ?RegisteredClient
        {
            return null;
        }

        public function findByClientId(string $clientId): ?RegisteredClient
        {
            return null;
        }

        public function save(RegisteredClient $client): void {}

        /** @return list<RegisteredClient> */
        public function all(): array
        {
            throw new ConfigurationException('Client `legacy` has an unknown authorization grant type `implicit`.');
        }
    };

    expect((new AuthorizationServerSchemeContributor($settings, $refusing))->schemes()[0]->definition['flows'])->toBe([
        'authorizationCode' => [
            'authorizationUrl' => 'https://auth.example.com/oauth2/authorize',
            'tokenUrl' => 'https://auth.example.com/oauth2/token',
            'scopes' => [],
        ],
    ]);
});
