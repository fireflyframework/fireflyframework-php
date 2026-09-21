<?php

declare(strict_types=1);

use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Firefly\Security\Jwt\JwtService;
use Firefly\Security\Session\SessionSecurityContextRepository;
use Firefly\Security\Tests\Support\SecurityCapstoneTestCase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Session security AND the local-JWT filter on together — the composition the reference config invites — so
 * what the session does and does not carry is pinned rather than implied. A bearer principal is re-verified on
 * every request and never stored; a context an interactive mechanism stored through the repository is loaded
 * when no bearer is sent; and a context a controller sets programmatically is NOT saved while the local-JWT
 * filter is on, because that filter clears the holder UNCONDITIONALLY in its own `finally` before the
 * persistence filter's exit sees it — the resource-server filter does not (it clears only a bearer context it
 * established itself; SessionWithOAuth2FlowTest pins that side). Sign someone in through the repository, not
 * the holder, when that is what you want under either.
 */
abstract class SessionWithJwtCapstoneTestCase extends SecurityCapstoneTestCase
{
    protected function securityOverrides(): array
    {
        return [
            'firefly.security.session.enabled' => true,
            'firefly.security.jwt.enabled' => true,
            'firefly.security.jwt.secret' => str_repeat('s', 40),
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/open/sign-in-fixture', function (Request $request): array {
            $context = new SecurityContext(Authentication::authenticated('ada', 'ada', [new SimpleGrantedAuthority('ROLE_USER')]));
            (new SessionSecurityContextRepository)->save($context, $request);

            return ['stored' => true];
        });

        Route::get('/open/promote', function (): array {
            SecurityContextHolder::setContext(new SecurityContext(Authentication::authenticated('root', 'root', [new SimpleGrantedAuthority('ROLE_ADMIN')])));

            return ['promoted' => true];
        });
    }

    protected function bearerFor(string $subject): string
    {
        /** @var JwtService $jwt */
        $jwt = $this->app()->make(JwtService::class);

        return 'Bearer '.$jwt->encode(['sub' => $subject, 'authorities' => ['ROLE_USER']], 3600);
    }
}

uses(SessionWithJwtCapstoneTestCase::class);

it('does not carry a bearer principal in the session: the next request needs the token again', function () {
    /** @var SessionWithJwtCapstoneTestCase $this */
    $first = $this->withHeader('Authorization', $this->bearerFor('ada'))->getJson('/whoami');
    $first->assertOk()->assertJson(['name' => 'ada', 'authenticated' => true]);

    // The session cookie alone — no Authorization header — is a stranger: a bearer principal is re-verified on
    // every request by design, never stored.
    $this->forgetSession();
    $this->flushHeaders()->followSession($first)->getJson('/whoami')->assertStatus(401);
});

it('loads a context an interactive mechanism stored when no bearer is sent, and lets a bearer win when one is', function () {
    /** @var SessionWithJwtCapstoneTestCase $this */
    $stored = $this->post('/open/sign-in-fixture');
    $stored->assertOk();

    $this->forgetSession();
    $this->followSession($stored)->getJson('/whoami')
        ->assertOk()
        ->assertJson(['name' => 'ada', 'authorities' => ['ROLE_USER'], 'authenticated' => true]);

    // A present bearer is authenticated on its own terms even when the session holds someone else.
    $this->forgetSession();
    $this->withHeader('Authorization', $this->bearerFor('grace'))->followSession($stored)->getJson('/whoami')
        ->assertOk()
        ->assertJson(['name' => 'grace']);
});

it('does not save a context a controller set on the holder while the local-JWT filter is on', function () {
    /** @var SessionWithJwtCapstoneTestCase $this */
    $promoted = $this->get('/open/promote');
    $promoted->assertOk();

    // The JWT filter's `finally` cleared the holder — no bearer was even presented — before the persistence
    // filter's exit could see root, so nothing reached the session: the limitation the reference config and
    // the filter docblock state for this filter, and this filter only.
    $this->forgetSession();
    $this->followSession($promoted)->getJson('/whoami')->assertStatus(401);
});
