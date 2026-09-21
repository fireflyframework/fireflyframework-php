<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Tests\Support;

use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Data\DataServiceProvider;
use Firefly\Security\OAuth2\Server\Jose\JwtGenerator;
use Firefly\Security\OAuth2\Server\Jose\KeyPairGenerator;
use Firefly\Security\OAuth2\Server\SecurityOAuth2ServerServiceProvider;
use Firefly\Security\OAuth2\Server\SecurityOAuth2ServerWiringProvider;
use Firefly\Security\SecurityServiceProvider;
use Firefly\Security\SecurityWiringProvider;
use Firefly\Security\Tests\Support\SecurityFlows;
use Firefly\Testing\Double\RecordingAuthenticationEvents;
use Firefly\Testing\FireflyDatabaseTestCase;
use Firefly\Testing\Security\OAuth2\OAuth2ServerTestClient;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\WebServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Session\SessionManager;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The REAL pipeline for every authorization-server flow: the shipped providers of web, data, cqrs, security and
 * this package under Testbench, a FILE session driver in a per-class temp directory (a session survives between
 * requests only when its cookie is carried), the fixtures under tests/Fixtures/Flows scanned in-process, memory
 * users ada/secret (ROLE_USER) and root/secret (ROLE_ADMIN), form login, CSRF and deny-by-default URL rules
 * (`open/*` public, everything else authenticated — the server's endpoints are answered ahead of those rules),
 * the resource-server filter pointed at the local key set, and three clients:
 *
 *   web-app     confidential (basic + post, {noop}web-secret), code + refresh + client credentials, consent
 *               required, redirect REDIRECT_URI, scopes openid profile email orders:read
 *   public-spa  public (none, PKCE), code only, no consent, redirect SPA_REDIRECT_URI, scopes openid profile
 *   svc         confidential (basic, {noop}svc-secret), client credentials only, scopes orders:read client.create
 *
 * Subclasses add keys through serverOverrides() and replace the clients through clients(); both are read before
 * boot. The helpers are PUBLIC (Pest 4 types a closure's $this as the TestCall).
 */
abstract class OAuth2ServerCapstoneTestCase extends FireflyDatabaseTestCase
{
    use SecurityFlows;

    public const string ISSUER = 'http://localhost';

    public const string WEB_APP_SECRET = 'web-secret';

    public const string SVC_SECRET = 'svc-secret';

    public const string REDIRECT_URI = 'https://client.test/callback';

    public const string POST_LOGOUT_URI = 'https://client.test/signed-out';

    public const string SPA_REDIRECT_URI = 'https://spa.test/cb';

    public RecordingAuthenticationEvents $events;

    private static ?string $signingKey = null;

    /** One RSA key per process: generating a 2048-bit key per test would cost seconds for nothing. */
    public static function signingKey(): string
    {
        return self::$signingKey ??= KeyPairGenerator::generate('RS256');
    }

    protected function fireflyProviders(): array
    {
        return [
            ValidationServiceProvider::class,
            WebServiceProvider::class,
            DataServiceProvider::class,
            CqrsServiceProvider::class,
            CqrsWiringProvider::class,
            SecurityServiceProvider::class,
            SecurityWiringProvider::class,
            SecurityOAuth2ServerServiceProvider::class,
            SecurityOAuth2ServerWiringProvider::class,
        ];
    }

    /** @return array<string,string> */
    protected function fixturePaths(): array
    {
        return ['Firefly\\Security\\OAuth2\\Server\\Tests\\Fixtures\\Flows\\' => dirname(__DIR__).'/Fixtures/Flows'];
    }

    /** @return array<string, mixed> extra config keys, seeded before boot */
    protected function serverOverrides(): array
    {
        return [];
    }

    /** @return array<string, mixed> the `clients` map */
    protected function clients(): array
    {
        return [
            'web-app' => [
                'client_secret' => '{noop}'.self::WEB_APP_SECRET,
                'client_name' => 'The web application',
                'client_authentication_methods' => ['client_secret_basic', 'client_secret_post'],
                'authorization_grant_types' => ['authorization_code', 'refresh_token', 'client_credentials'],
                'redirect_uris' => [self::REDIRECT_URI],
                'post_logout_redirect_uris' => [self::POST_LOGOUT_URI],
                'scopes' => ['openid', 'profile', 'email', 'orders:read'],
            ],
            'public-spa' => [
                'client_name' => 'The SPA',
                'client_authentication_methods' => ['none'],
                'authorization_grant_types' => ['authorization_code'],
                'redirect_uris' => [self::SPA_REDIRECT_URI],
                'scopes' => ['openid', 'profile'],
                'client_settings' => ['require_authorization_consent' => false],
            ],
            'svc' => [
                'client_secret' => '{noop}'.self::SVC_SECRET,
                'client_authentication_methods' => ['client_secret_basic'],
                'authorization_grant_types' => ['client_credentials'],
                'scopes' => ['orders:read', 'client.create'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            'app.url' => self::ISSUER,
            'app.env' => 'testing',
            'app.debug' => false,
            'session.driver' => 'file',
            'session.files' => $this->sessionDir(),
            'session.cookie' => 'firefly_session',
            'firefly.scan.paths' => $this->fixturePaths(),
            'firefly.security.enabled' => true,
            'firefly.security.form_login.enabled' => true,
            'firefly.security.csrf.enabled' => true,
            'firefly.security.http.enabled' => true,
            'firefly.security.http.rules' => [
                ['pattern' => 'open', 'access' => 'permitAll'],
                ['pattern' => 'open/*', 'access' => 'permitAll'],
                ['pattern' => 'api/*', 'access' => 'authenticated'],
                ['pattern' => '*', 'access' => 'authenticated'],
            ],
            'firefly.security.users' => [
                'ada' => ['password' => '{bcrypt}'.password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]), 'authorities' => ['ROLE_USER']],
                'root' => ['password' => '{bcrypt}'.password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]), 'authorities' => ['ROLE_ADMIN']],
            ],
            'firefly.security.oauth2.resource_server.enabled' => true,
            'firefly.security.oauth2.resource_server.jwks_source' => 'local',
            'firefly.security.oauth2.resource_server.jwks_uri' => self::ISSUER.'/oauth2/jwks',
            'firefly.security.oauth2.resource_server.issuer' => self::ISSUER,
            'firefly.security.oauth2.server.enabled' => true,
            'firefly.security.oauth2.server.issuer' => self::ISSUER,
            'firefly.security.oauth2.server.jwt.signing_key' => self::signingKey(),
            'firefly.security.oauth2.server.clients' => $this->clients(),
            ...$this->serverOverrides(),
        ];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        $this->events = new RecordingAuthenticationEvents;
        $app->instance(ApplicationEventPublisher::class, $this->events);
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Boot publishes its own lifecycle events through the same port; start every test from silence.
        $this->events->reset();
    }

    protected function sessionDir(): string
    {
        $dir = sys_get_temp_dir().'/firefly-oauth2-server-sessions-'.str_replace('\\', '_', static::class);
        if (! is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }

        return $dir;
    }

    /** Forget the in-memory session store, as a new PHP process would (see SecurityCapstoneTestCase). */
    public function forgetSession(): void
    {
        /** @var SessionManager $manager */
        $manager = $this->app()->make('session');
        $manager->forgetDrivers();
    }

    public function oauth2(): OAuth2ServerTestClient
    {
        return new OAuth2ServerTestClient($this);
    }

    /**
     * Sign in through the framework's login page and keep the session cookie on every following request, so
     * the next authorization request finds a session-held principal — what a browser does.
     *
     * @return TestResponse<Response> the redirect the login POST answered with
     */
    public function signIn(string $username = 'ada', string $password = 'secret'): TestResponse
    {
        $page = $this->get('/login');
        $response = $this->followSession($page)->post('/login', ['username' => $username, 'password' => $password, '_token' => $this->csrfTokenFrom($page)]);
        $this->followSession($response);

        return $response;
    }

    /**
     * @return array<string,mixed>
     */
    public function decodeJwt(string $jwt): array
    {
        /** @var JwtGenerator $generator */
        $generator = $this->app()->make(JwtGenerator::class);

        return $generator->decode($jwt);
    }
}
