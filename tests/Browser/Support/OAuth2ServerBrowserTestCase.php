<?php

declare(strict_types=1);

namespace Firefly\Tests\Browser\Support;

use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\OAuth2\Server\Client\RegisteredClientFactory;
use Firefly\Security\OAuth2\Server\Client\RegisteredClientRepository;
use Firefly\Security\OAuth2\Server\Jose\KeyPairGenerator;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Route;

/**
 * The skeleton as an OAuth2 authorization server AND the relying party, in one process: form login with one
 * memory user (ada/secret), CSRF, deny-by-default URL rules that leave the fixture pages open and protect
 * `api/*`, the resource-server filter pointed at the server's own keys, and one confidential client,
 * `browser-rp`, whose redirect URI is registered once the plugin's origin is known (registerRelyingParty()) —
 * the in-process server picks its port when the first page is visited, so the config block carries a
 * placeholder and the test replaces the client through the repository's save().
 *
 * Three fixture routes, registered in setUp() exactly as BrowserTestCase registers /browser-fixture/*: the
 * relying party's start page (shows the origin), its callback (shows the code and state, or the error) and a
 * bearer-protected API under api/*.
 */
abstract class OAuth2ServerBrowserTestCase extends BrowserTestCase
{
    public const string CLIENT_ID = 'browser-rp';

    public const string CLIENT_SECRET = 'browser-secret';

    public const string CALLBACK_PATH = '/browser-fixture/rp/callback';

    private static ?string $signingKey = null;

    /** One RSA key per process: generating a 2048-bit key per test would cost seconds for nothing. */
    public static function signingKey(): string
    {
        return self::$signingKey ??= KeyPairGenerator::generate('RS256');
    }

    /** @return array<string, mixed> */
    protected function securityOverrides(): array
    {
        return [
            'session.driver' => 'file',
            'session.files' => $this->sessionDir(),
            'firefly.security.enabled' => true,
            'firefly.security.form_login.enabled' => true,
            'firefly.security.csrf.enabled' => true,
            'firefly.security.http.enabled' => true,
            'firefly.security.http.rules' => [
                ['pattern' => 'api/*', 'access' => 'authenticated'],
                ['pattern' => '*', 'access' => 'permitAll'],
            ],
            'firefly.security.users' => [
                'ada' => ['password' => '{bcrypt}'.password_hash('secret', PASSWORD_BCRYPT, ['cost' => 4]), 'authorities' => ['ROLE_USER']],
            ],
            'firefly.security.oauth2.resource_server.enabled' => true,
            'firefly.security.oauth2.resource_server.jwks_source' => 'local',
            'firefly.security.oauth2.resource_server.jwks_uri' => 'http://localhost/oauth2/jwks',
            'firefly.security.oauth2.resource_server.issuer' => 'http://localhost',
            'firefly.security.oauth2.server.enabled' => true,
            'firefly.security.oauth2.server.issuer' => 'http://localhost',
            'firefly.security.oauth2.server.jwt.signing_key' => self::signingKey(),
            'firefly.security.oauth2.server.clients' => [
                self::CLIENT_ID => $this->clientBlock('http://127.0.0.1'.self::CALLBACK_PATH),
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/browser-fixture/rp/start', static fn (Request $request): string => '<!DOCTYPE html><html><body>'
            .'<h1>Relying party</h1><p>Origin: <code id="origin">'.htmlspecialchars($request->getSchemeAndHttpHost(), ENT_QUOTES).'</code></p>'
            .'</body></html>');

        Route::get(self::CALLBACK_PATH, static function (Request $request): string {
            $code = $request->query('code');
            $error = $request->query('error');
            $state = $request->query('state');
            $body = is_string($code)
                ? '<h1>Authorization code received</h1><p>code: <code id="code">'.htmlspecialchars($code, ENT_QUOTES).'</code></p>'
                : '<h1>Authorization refused</h1><p>error: <code id="error">'.htmlspecialchars(is_string($error) ? $error : 'unknown', ENT_QUOTES).'</code></p>';

            return '<!DOCTYPE html><html><body>'.$body.'<p>state: <code id="state">'.htmlspecialchars(is_string($state) ? $state : '', ENT_QUOTES).'</code></p></body></html>';
        });

        Route::get('/api/browser-fixture/profile', static fn (): JsonResponse => new JsonResponse([
            'sub' => SecurityContextHolder::getAuthentication()?->getName() ?? 'anonymous',
            'authorities' => SecurityContextHolder::getAuthentication()?->authorityStrings() ?? [],
        ]));
    }

    /** Register the relying party's REAL redirect URI (the plugin's origin + the callback path); returns it. */
    public function registerRelyingParty(string $origin): string
    {
        $redirectUri = $origin.self::CALLBACK_PATH;
        /** @var RegisteredClientRepository $clients */
        $clients = $this->app()->make(RegisteredClientRepository::class);
        /** @var AuthorizationServerSettings $settings */
        $settings = $this->app()->make(AuthorizationServerSettings::class);
        $clients->save(RegisteredClientFactory::fromConfig(self::CLIENT_ID, $this->clientBlock($redirectUri), $settings));

        return $redirectUri;
    }

    /** `scheme://host[:port]` of a page URL. */
    public function originOf(string $url): string
    {
        $parts = parse_url($url);
        $origin = ($parts['scheme'] ?? 'http').'://'.($parts['host'] ?? '127.0.0.1');

        return isset($parts['port']) ? $origin.':'.$parts['port'] : $origin;
    }

    /**
     * Forget the in-memory session store, as a new PHP process would.
     *
     * The plugin serves Chromium from THIS process, so the SessionManager's driver still holds the browser's
     * session — and Laravel's Store::start() MERGES what the handler returns onto the attributes it already
     * carries, so an in-process request with no session cookie would be handed the browser's signed-in
     * context and the persistence filter at -94 would name ada before the resource-server filter at -85 ever
     * looked at the bearer. The same call the capstone suite makes for the same reason.
     */
    public function forgetSession(): void
    {
        /** @var SessionManager $manager */
        $manager = $this->app()->make('session');
        $manager->forgetDrivers();
    }

    /**
     * @return array<string,mixed>
     */
    private function clientBlock(string $redirectUri): array
    {
        return [
            'client_secret' => '{noop}'.self::CLIENT_SECRET,
            'client_name' => 'The browser relying party',
            'client_authentication_methods' => ['client_secret_basic'],
            'authorization_grant_types' => ['authorization_code', 'refresh_token'],
            'redirect_uris' => [$redirectUri],
            'scopes' => ['openid', 'profile'],
        ];
    }

    private function sessionDir(): string
    {
        $dir = sys_get_temp_dir().'/firefly-oauth2-browser-sessions-'.getmypid();
        if (! is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }

        return $dir;
    }
}
