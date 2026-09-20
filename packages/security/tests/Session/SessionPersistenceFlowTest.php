<?php

declare(strict_types=1);

use Firefly\Context\Boot\FireflyKernel;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Firefly\Security\Session\SecurityContextPersistenceFilter;
use Firefly\Security\Session\SessionSecurityBootstrap;
use Firefly\Security\Session\SessionSecurityContextRepository;
use Firefly\Security\Tests\Support\SecurityCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;
use Firefly\Security\Web\HttpSecurityFilter;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use RuntimeException;

/**
 * Session security on by its own key — no form login yet — so the persistence filter and the bootstrap are
 * proven on their own: a test-only route stores a context the way a login filter will, and the next request
 * with the cookie is authenticated by nothing but the session.
 */
abstract class SessionOnlyCapstoneTestCase extends SecurityCapstoneTestCase
{
    protected function securityOverrides(): array
    {
        return ['firefly.security.session.enabled' => true];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/open/sign-in-fixture', function (Request $request): array {
            $context = new SecurityContext(Authentication::authenticated('ada', 'ada', [new SimpleGrantedAuthority('ROLE_USER')]));
            (new SessionSecurityContextRepository)->save($context, $request);

            return ['stored' => true];
        });
    }
}

uses(SessionOnlyCapstoneTestCase::class, SecurityFlows::class);

it('runs the session middleware globally, ahead of the security filters, and strips it from routes', function () {
    /** @var SessionOnlyCapstoneTestCase $this */
    /** @var FoundationHttpKernel $kernel */
    $kernel = $this->app()->make(HttpKernelContract::class);
    $global = $kernel->getGlobalMiddleware();

    foreach (SessionSecurityBootstrap::MIDDLEWARE as $middleware) {
        expect($global)->toContain($middleware);
    }

    $position = static function (string $class) use ($global): int {
        $index = array_search($class, $global, true);
        if (! is_int($index)) {
            throw new RuntimeException($class.' is not on the global middleware stack.');
        }

        return $index;
    };

    expect($position(EncryptCookies::class))->toBeLessThan($position(StartSession::class))
        ->and($position(StartSession::class))->toBeLessThan($position(SecurityContextPersistenceFilter::class))
        ->and($position(SecurityContextPersistenceFilter::class))->toBeLessThan($position(HttpSecurityFilter::class));

    // A route that was given the same classes explicitly (the admin dashboard does this) must not run them
    // a second time: a second EncryptCookies pass would null every cookie and StartSession would mint a new
    // session. The bootstrap excludes them on every registered route; re-running it here covers this
    // test-time route exactly as the boot covered the fixtures' routes.
    Route::get('/open/doubled', static fn (): string => 'ok')->middleware(SessionSecurityBootstrap::MIDDLEWARE);
    (new SessionSecurityBootstrap)->run($this->app()->make(FireflyKernel::class)->context());

    $first = $this->get('/open/doubled');
    $first->assertOk();
    $id = (string) $first->getCookie($this->sessionCookieName())?->getValue();

    $this->forgetSession();
    $second = $this->followSession($first)->get('/open/doubled');
    $second->assertOk();

    expect((string) $second->getCookie($this->sessionCookieName())?->getValue())->toBe($id);
});

it('authenticates the next request from the session alone, and only while the cookie is carried', function () {
    /** @var SessionOnlyCapstoneTestCase $this */
    $stored = $this->post('/open/sign-in-fixture');
    $stored->assertOk();

    $this->forgetSession();
    $this->followSession($stored)->getJson('/whoami')
        ->assertOk()
        ->assertJson(['name' => 'ada', 'authorities' => ['ROLE_USER'], 'authenticated' => true]);

    // The holder never bleeds past the request.
    expect(SecurityContextHolder::getContext()->isAuthenticated())->toBeFalse();

    $this->forgetSession();
    $this->forgetCookies();
    $this->getJson('/whoami')->assertStatus(401);
});

it('saves a context a controller established programmatically', function () {
    /** @var SessionOnlyCapstoneTestCase $this */
    Route::get('/open/promote', function (): array {
        SecurityContextHolder::setContext(new SecurityContext(Authentication::authenticated('root', 'root', [new SimpleGrantedAuthority('ROLE_ADMIN')])));

        return ['promoted' => true];
    });

    $promoted = $this->get('/open/promote');
    $promoted->assertOk();

    $this->forgetSession();
    $this->followSession($promoted)->getJson('/whoami')->assertJson(['name' => 'root', 'authorities' => ['ROLE_ADMIN']]);
});
