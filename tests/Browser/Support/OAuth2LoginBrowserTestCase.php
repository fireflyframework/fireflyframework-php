<?php

declare(strict_types=1);

namespace Firefly\Tests\Browser\Support;

use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\OAuth2\Client\User\OAuth2AuthenticationToken;
use Firefly\Security\OAuth2\Client\User\OidcUser;
use Firefly\Testing\Security\OAuth2\FakeAuthorizationServer;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Pest\Browser\Drivers\LaravelHttpServer;
use Pest\Browser\ServerManager;
use RuntimeException;

/**
 * The skeleton with OpenID Connect login ON — and nothing else to sign in with: every path but the fake
 * provider's front channel needs a principal, so the entry point sends a browser to the framework's login
 * page, whose only way in is "Sign in with Fake IdP". The FakeAuthorizationServer is mounted on the same
 * in-process app the plugin serves to Chromium, at {origin}/fake-idp: its /authorize and /end-session are
 * real routes the browser is redirected to, and its back channel answers the real discovery, token, JWKS and
 * userinfo calls the framework makes while handling those requests.
 *
 * THE ORIGIN IS KNOWN BEFORE THE SERVER STARTS. The provider's issuer must be an absolute URL at boot
 * (discovery's `issuer`, the id token's `iss`, the URLs the browser is sent to), and the plugin starts its
 * server only when a test is marked as a browser test — after setUp(). It does, however, memoise the server
 * object, host and port included, on the first ServerManager::http() call, and starts THAT object later; so
 * asking for it from configOverrides() pins the port the test will be served on.
 *
 * A FILE session in a per-class temp dir, so the suite can read what a session store would hold: never a
 * token in clear. RP-initiated logout is on, so "Sign out" on the fixture page ends the provider's session too.
 */
abstract class OAuth2LoginBrowserTestCase extends BrowserTestCase
{
    public FakeAuthorizationServer $idp;

    /** The in-process server's origin, `http://127.0.0.1:{port}`. */
    public static function origin(): string
    {
        $http = ServerManager::instance()->http();
        if (! $http instanceof LaravelHttpServer) {
            throw new RuntimeException('The OAuth2 browser suite needs the plugin\'s in-process Laravel server, and got '.$http::class.'.');
        }

        return sprintf('http://%s:%d', $http->host, $http->port);
    }

    public static function issuer(): string
    {
        return self::origin().'/fake-idp';
    }

    public static function sessionDir(): string
    {
        $dir = sys_get_temp_dir().'/firefly-browser-oauth2-sessions';
        if (! is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }

        return $dir;
    }

    protected function securityOverrides(): array
    {
        return [
            'session.driver' => 'file',
            'session.files' => self::sessionDir(),
            'session.cookie' => 'firefly_session',
            'firefly.security.enabled' => true,
            'firefly.security.http.enabled' => true,
            'firefly.security.http.rules' => [
                ['pattern' => 'fake-idp/*', 'access' => 'permitAll'],
                ['pattern' => '*', 'access' => 'authenticated'],
            ],
            'firefly.security.oauth2.client.enabled' => true,
            'firefly.security.oauth2.client.login.enabled' => true,
            'firefly.security.oauth2.client.logout.oidc_initiated' => true,
            'firefly.security.oauth2.client.registration.fake' => FakeAuthorizationServer::registrationConfig(),
            'firefly.security.oauth2.client.provider.fake' => FakeAuthorizationServer::providerConfig(self::issuer()),
        ];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        parent::defineFireflyEnvironment($app);

        $this->idp = FakeAuthorizationServer::install($app, self::issuer());
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->defineAccountRoute();
    }

    /**
     * The content of every session file the suite has written.
     *
     * @return list<string>
     */
    public function sessionFiles(): array
    {
        $contents = [];
        foreach (glob(self::sessionDir().'/*') ?: [] as $file) {
            if (is_file($file)) {
                $contents[] = (string) file_get_contents($file);
            }
        }

        return $contents;
    }

    /**
     * A protected page that shows who is signed in and offers the one thing the framework page does not: a
     * sign-out form posting the session token, the way an application's own layout would.
     */
    private function defineAccountRoute(): void
    {
        Route::get('/browser-fixture/account', static function (Request $request): Response {
            $user = OAuth2AuthenticationToken::principal(SecurityContextHolder::getAuthentication());
            $name = $user?->getName() ?? 'nobody';
            $email = $user instanceof OidcUser ? ($user->getEmail() ?? '') : '';
            $token = $request->hasSession() ? $request->session()->token() : '';
            $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Account</title></head><body>'
                .'<h1>Account</h1><p id="who">Signed in as '.$e($name).'</p><p id="email">'.$e($email).'</p>'
                .'<form method="post" action="/logout"><input type="hidden" name="_token" value="'.$e($token).'"><button type="submit">Sign out</button></form>'
                .'</body></html>';

            return new Response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
        });
    }
}
