<?php

declare(strict_types=1);

use Firefly\Security\Authentication\AuthenticationManager;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Firefly\Security\Session\SecurityContextPersistenceFilter;
use Firefly\Security\Session\SessionSecurityBootstrap;
use Firefly\Security\Session\SessionSecurityContextRepository;
use Firefly\Security\Tests\Support\SecurityCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;
use Firefly\Security\User\User;
use Firefly\Security\Web\HttpSecurityFilter;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Http\Request;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

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

        // What a login filter does: the real AuthenticationManager (DaoAuthenticationProvider over the memory
        // users), whose token carries the full User — encoded password included — saved through the repository.
        Route::post('/open/sign-in-dao', function (Request $request, AuthenticationManager $manager): array {
            $authentication = $manager->authenticate(Authentication::unauthenticated('ada', 'ada', 'secret'));
            (new SessionSecurityContextRepository)->save(new SecurityContext($authentication), $request);

            return ['password' => $authentication->getPrincipal() instanceof User ? $authentication->getPrincipal()->getPassword() : ''];
        });
    }

    /**
     * The bytes the file driver wrote for the session a response set, as the next process will read them.
     *
     * @param  TestResponse<Response>  $response
     */
    public function sessionFileFor(TestResponse $response): string
    {
        $id = (string) $response->getCookie($this->sessionCookieName())?->getValue();
        $file = $this->sessionDir().'/'.$id;
        if ($id === '' || ! is_file($file)) {
            throw new RuntimeException('No session file was written for the response.');
        }

        return (string) file_get_contents($file);
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
    // session. The exclusion is applied when the route is MATCHED, so a route registered after boot — this
    // one — is covered exactly like the fixtures' routes, with nothing to re-run.
    Route::get('/open/doubled', static fn (): string => 'ok')->middleware(SessionSecurityBootstrap::MIDDLEWARE);

    $first = $this->get('/open/doubled');
    $first->assertOk();
    $id = (string) $first->getCookie($this->sessionCookieName())?->getValue();

    $this->forgetSession();
    $second = $this->followSession($first)->get('/open/doubled');
    $second->assertOk();

    expect((string) $second->getCookie($this->sessionCookieName())?->getValue())->toBe($id);
});

it('strips the session middleware from a route the compiled (route:cache) collection dispatches, and bakes nothing into the cache', function () {
    /** @var SessionOnlyCapstoneTestCase $this */
    Route::get('/open/doubled', static fn (): string => 'ok')->middleware(SessionSecurityBootstrap::MIDDLEWARE)->name('doubled');

    /** @var Router $router */
    $router = $this->app()->make('router');
    $routes = $router->getRoutes();
    if (! $routes instanceof RouteCollection) {
        throw new RuntimeException('The router holds a '.$routes::class.', not the RouteCollection route:cache compiles.');
    }
    /** @var array{compiled: array<mixed>, attributes: array<string, array{action: array<string, mixed>}>} $compiled */
    $compiled = $routes->compile();

    // What `route:cache` would write: no route — neither one the boot registered (/whoami) nor this one —
    // carries the exclusion in its cached attributes, so a cache written with session security on serves an
    // application that later turns it off with its `web` session middleware intact, and vice versa.
    foreach ($compiled['attributes'] as $name => $attributes) {
        /** @var list<mixed> $excluded */
        $excluded = (array) ($attributes['action']['excluded_middleware'] ?? []);
        foreach (SessionSecurityBootstrap::MIDDLEWARE as $middleware) {
            expect(in_array($middleware, $excluded, true))->toBeFalse("route {$name} bakes {$middleware} into the route cache");
        }
    }

    // CompiledRouteCollection builds a FRESH Route from those attributes on every match, so an exclusion
    // written onto the boot-time Route objects would never reach the instance the stack runs. The listener
    // excludes on the matched instance, whichever collection produced it.
    $router->setCompiledRoutes($compiled);
    expect($router->getRoutes())->toBeInstanceOf(CompiledRouteCollection::class);

    $first = $this->get('/open/doubled');
    $first->assertOk();
    $id = (string) $first->getCookie($this->sessionCookieName())?->getValue();
    expect($id)->not->toBe('');

    $this->forgetSession();
    $second = $this->followSession($first)->get('/open/doubled');
    $second->assertOk();

    expect((string) $second->getCookie($this->sessionCookieName())?->getValue())->toBe($id);

    // The compiled collection hands the SAME Route instance to both requests (its name cache), as an Octane
    // worker does for every route: the exclusion is written once, not appended per request.
    $matched = $router->current();
    expect($matched)->not->toBeNull()
        ->and($matched?->excludedMiddleware())->toBe(SessionSecurityBootstrap::MIDDLEWARE);

    // The fixtures' routes dispatch through the compiled collection too, and the session still carries the
    // principal across them.
    $stored = $this->post('/open/sign-in-fixture');
    $stored->assertOk();
    $this->forgetSession();
    $this->followSession($stored)->getJson('/whoami')->assertOk()->assertJson(['name' => 'ada']);
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

it('writes a signed-in user to the session without the encoded password, and the next request is still that user', function () {
    /** @var SessionOnlyCapstoneTestCase $this */
    $stored = $this->postJson('/open/sign-in-dao');
    $stored->assertOk();

    // The token the provider returned carried the bcrypt hash; the bytes the file driver wrote do not.
    /** @var string $hash */
    $hash = $stored->json('password');
    expect($hash)->toStartWith('{bcrypt}$2y$');

    $bytes = $this->sessionFileFor($stored);
    /** @var array<string,mixed> $attributes */
    $attributes = unserialize($bytes);
    // The store expands the dotted key into nested arrays, as it does for any attribute.
    /** @var SecurityContext $context */
    $context = Arr::get($attributes, SessionSecurityContextRepository::KEY);
    /** @var User $principal */
    $principal = $context->getAuthentication()?->getPrincipal();

    expect($bytes)->not->toContain('$2y$')
        ->and($bytes)->not->toContain($hash)
        ->and($principal)->toBeInstanceOf(User::class)
        ->and($principal->getUsername())->toBe('ada')
        ->and($principal->getPassword())->toBe('');

    // And the copy is a principal like any other on the next request.
    $this->forgetSession();
    $this->followSession($stored)->getJson('/whoami')
        ->assertOk()
        ->assertJson(['name' => 'ada', 'authorities' => ['ROLE_USER'], 'authenticated' => true]);
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
