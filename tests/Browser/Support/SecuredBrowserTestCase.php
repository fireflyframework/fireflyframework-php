<?php

declare(strict_types=1);

namespace Firefly\Tests\Browser\Support;

use Firefly\Security\Tests\Support\SecurityFlows;
use Firefly\Security\Web\EntryPoint\DelegatingAuthenticationEntryPoint;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Route;

/**
 * Deny-by-default URL security with the framework's REAL sign-in behind it: the master flag, the URL filter,
 * form login (which implies the session-persisted SecurityContext and the logout filter), and two memory
 * users — ada (ROLE_ADMIN) and bob (ROLE_USER), bcrypt-encoded behind the DelegatingPasswordEncoder's
 * `{bcrypt}` prefix. The rules let the login and logout paths through and demand ROLE_ADMIN everywhere else,
 * so bob is the authenticated-but-refused principal every 403 scenario needs and ada is the one who gets in.
 * The wave-E `?as=user` stand-in middleware this fixture used to lean on is gone: BrowserTestCase no longer
 * prepends anything to the kernel, and a browser is a principal only once it has signed in.
 *
 * THE ENTRY POINT IS `problem` HERE, AND `auto` IN SignedInBrowserTestCase. With form login on, `auto` sends
 * a browser to the login page and there is no 401 page to photograph; `problem` keeps the 401 page for an
 * anonymous browser while GET /login (always permitted by HttpSecurityFilter) and POST /login (answered by
 * FormLoginFilter before the rules run) still let a person sign in. The error-page scenarios sit here; the
 * sign-in flow sits on the subclass.
 *
 * SESSIONS ARE FILES, AND THE STORE IS FORGOTTEN AFTER EVERY REQUEST. The plugin serves every browser request
 * from ONE long-lived process, and Laravel's session Store is a singleton whose loadSession() array_replace()s
 * the handler's data OVER the attributes it still holds from the previous request. Testbench's default
 * `array` driver lives inside that same Store, so a request that carries no cookie at all would still read the
 * last request's attributes — verified: without the forget below, a brand-new browser context (no cookie)
 * visiting /orders right after ada signed in was answered as ada. So the driver is `file` under the compile
 * temp dir (a session genuinely survives only when its cookie is carried, exactly as under PHP-FPM), and an
 * Application::terminating() callback — which Kernel::terminate() runs after every request the plugin or the
 * Laravel test client hands the kernel — forgets the manager's driver and the resolved `session.store`
 * singleton, so the next request builds a fresh Store over the file for the cookie it carries. This is the
 * browser-side twin of SecurityCapstoneTestCase::forgetSession().
 *
 * The fixture route GET /browser-fixture/sign-out renders the one thing the framework does not: a form that
 * posts the session token to /logout. No framework page carries a logout control (the login page is the only
 * page the framework renders and /orders is JSON), and LogoutFilter accepts POST with the session CSRF token
 * only, so a scenario submits this form rather than navigating to /logout.
 *
 * Every helper a Pest closure calls on $this is PUBLIC (Pest 4 types the closure's $this as the TestCall).
 * SecurityFlows adds followSession()/csrfTokenFrom()/forgetCookies() for the Node-free default-gate test of
 * this fixture, tests/BrowserSignInFixtureTest.php.
 */
abstract class SecuredBrowserTestCase extends BrowserTestCase
{
    use SecurityFlows;

    public const string ADMIN = 'ada';

    public const string ADMIN_PASSWORD = 'analytical-engine';

    public const string USER = 'bob';

    public const string USER_PASSWORD = 'plain-user';

    /**
     * The login page's submit button, as a CSS selector. `press('Sign in')` would NOT do: the page has an
     * `<h1>Sign in</h1>` before the `<button>Sign in</button>`, the plugin's text lookup is non-strict, and
     * Playwright clicks the first match — the heading, which submits nothing.
     */
    public const string SIGN_IN_BUTTON = 'form.form button[type="submit"]';

    public const string SIGN_OUT_PATH = '/browser-fixture/sign-out';

    /** `firefly.security.http.entry_point`: what an anonymous browser gets at a protected URL. */
    protected function entryPoint(): string
    {
        return DelegatingAuthenticationEntryPoint::PROBLEM;
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'session.driver' => 'file',
            'session.files' => self::sessionDir(),
            'session.cookie' => 'larafly_session',
        ];
    }

    protected function securityOverrides(): array
    {
        return [
            'firefly.security.enabled' => true,
            'firefly.security.http.enabled' => true,
            'firefly.security.http.entry_point' => $this->entryPoint(),
            'firefly.security.http.rules' => [
                ['pattern' => 'login', 'access' => 'permitAll'],
                ['pattern' => 'logout', 'access' => 'permitAll'],
                ['pattern' => '*', 'access' => 'hasRole:ADMIN'],
            ],
            // Implies firefly.security.session.enabled (SessionSecuritySettings) and firefly.security.logout.enabled.
            'firefly.security.form_login.enabled' => true,
            'firefly.security.users' => [
                self::ADMIN => ['password' => self::encoded(self::ADMIN_PASSWORD), 'authorities' => ['ROLE_ADMIN']],
                self::USER => ['password' => self::encoded(self::USER_PASSWORD), 'authorities' => ['ROLE_USER']],
            ],
        ];
    }

    /** The session files, under the compile temp dir SkeletonExampleTestCase owns; emptied with it. */
    public static function sessionDir(): string
    {
        $dir = (string) self::$cacheDir.'/sessions';
        if (! is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }

        return $dir;
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$cacheDir !== null) {
            foreach (glob(self::$cacheDir.'/sessions/*') ?: [] as $file) {
                unlink($file);
            }
            @rmdir(self::$cacheDir.'/sessions');
        }

        parent::tearDownAfterClass();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->forgetSessionStoreBetweenRequests();
        $this->defineSignOutFixture();
    }

    /** `{bcrypt}` at cost 4: the encoder verifies any cost, and the hash is computed at every boot. */
    private static function encoded(string $password): string
    {
        return '{bcrypt}'.password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]);
    }

    private function forgetSessionStoreBetweenRequests(): void
    {
        $app = $this->app();
        $app->terminating(static function () use ($app): void {
            /** @var SessionManager $sessions */
            $sessions = $app->make('session');
            $sessions->forgetDrivers();
            $app->forgetInstance('session.store');
        });
    }

    private function defineSignOutFixture(): void
    {
        Route::get(self::SIGN_OUT_PATH, static function (Request $request): string {
            $token = htmlspecialchars($request->hasSession() ? $request->session()->token() : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

            return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Sign-out fixture</title></head><body>'
                .'<h1>Sign-out fixture</h1>'
                .'<form method="post" action="/logout"><input type="hidden" name="_token" value="'.$token.'">'
                .'<button id="sign-out" type="submit">Sign out</button></form>'
                .'</body></html>';
        });
    }
}
