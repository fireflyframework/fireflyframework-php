<?php

declare(strict_types=1);

use Firebase\JWT\JWT;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Firefly\Security\OAuth2\JwksDocumentSource;
use Firefly\Security\OAuth2\JwksProvider;
use Firefly\Security\OAuth2\LocalJwksProvider;
use Firefly\Security\OAuth2\OAuth2ResourceServerFilter;
use Firefly\Security\Session\SessionSecurityContextRepository;
use Firefly\Security\Tests\Support\SecurityCapstoneTestCase;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * Session security AND the OAuth2 resource-server filter on together — the twin of SessionWithJwtFlowTest,
 * because the two bearer filters do NOT treat the holder alike on exit and the reference states the
 * difference. A bearer principal is re-verified on every request and never stored, exactly as under the local
 * JWT. But the resource-server filter clears only a bearer context it established itself and passes a
 * bearer-less request straight through, so a context a controller sets on the holder on such a request IS
 * saved by the persistence filter's exit — the opposite of the JWT case — while one set on a request that did
 * present a bearer is cleared with that bearer and is not. The keys come the way an application that signs
 * its own tokens supplies them: `jwks_source: local` and a JwksDocumentSource bound before boot, so the
 * filter, its provider and its boot-time wiring are all the shipped ones.
 */
abstract class SessionWithOAuth2CapstoneTestCase extends SecurityCapstoneTestCase
{
    public const string SECRET = 'a-resource-server-secret-of-more-than-forty-characters';

    public const string KID = 'kid-1';

    protected function securityOverrides(): array
    {
        return [
            'firefly.security.session.enabled' => true,
            'firefly.security.oauth2.resource_server.enabled' => true,
            'firefly.security.oauth2.resource_server.jwks_source' => 'local',
            'firefly.security.oauth2.resource_server.jwks_uri' => 'http://localhost/.well-known/jwks.json',
        ];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        parent::defineFireflyEnvironment($app);

        $app->instance(JwksDocumentSource::class, new class implements JwksDocumentSource
        {
            public function jwks(): array
            {
                return ['keys' => [[
                    'kty' => 'oct',
                    'kid' => SessionWithOAuth2CapstoneTestCase::KID,
                    'alg' => 'HS256',
                    'k' => rtrim(strtr(base64_encode(SessionWithOAuth2CapstoneTestCase::SECRET), '+/', '-_'), '='),
                ]]];
            }
        });
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

    public function bearerFor(string $subject): string
    {
        return 'Bearer '.JWT::encode(['sub' => $subject, 'scope' => 'orders:read', 'exp' => time() + 3600], self::SECRET, 'HS256', self::KID);
    }
}

uses(SessionWithOAuth2CapstoneTestCase::class);

it('boots the shipped resource-server filter over the local key set', function () {
    /** @var SessionWithOAuth2CapstoneTestCase $this */
    expect($this->app()->make(OAuth2ResourceServerFilter::class))->toBeInstanceOf(OAuth2ResourceServerFilter::class)
        ->and($this->app()->make(JwksProvider::class))->toBeInstanceOf(LocalJwksProvider::class);
});

it('does not carry a bearer principal in the session: the next request needs the token again', function () {
    /** @var SessionWithOAuth2CapstoneTestCase $this */
    $first = $this->withHeader('Authorization', $this->bearerFor('svc-1'))->getJson('/whoami');
    $first->assertOk()->assertJson(['name' => 'svc-1', 'authorities' => ['SCOPE_orders:read'], 'authenticated' => true]);

    // The session cookie alone is a stranger: the filter cleared the bearer context it established, so the
    // persistence filter's exit had nothing to save.
    $this->forgetSession();
    $this->flushHeaders()->followSession($first)->getJson('/whoami')->assertStatus(401);
});

it('loads a context an interactive mechanism stored when no bearer is sent, and keeps it over a bearer when one is', function () {
    /** @var SessionWithOAuth2CapstoneTestCase $this */
    $stored = $this->post('/open/sign-in-fixture');
    $stored->assertOk();

    $this->forgetSession();
    $this->followSession($stored)->getJson('/whoami')
        ->assertOk()
        ->assertJson(['name' => 'ada', 'authorities' => ['ROLE_USER'], 'authenticated' => true]);

    // The resource-server filter no-ops when the holder is already authenticated (the session's ada), so a
    // present bearer does NOT win here — the -85 filter composes with an outer principal rather than
    // replacing it, the reverse of the local-JWT filter, which authenticates a presented bearer regardless.
    // Pinned as the filter's documented composition rule, seen with the session as the outer principal.
    $this->forgetSession();
    $this->withHeader('Authorization', $this->bearerFor('svc-1'))->followSession($stored)->getJson('/whoami')
        ->assertOk()
        ->assertJson(['name' => 'ada']);
});

it('saves a context a controller set on the holder when the request presented no bearer, and not when it did', function () {
    /** @var SessionWithOAuth2CapstoneTestCase $this */
    // No bearer: the resource-server filter passed straight through and cleared nothing, so the persistence
    // filter's exit saw root on the holder and saved it — the side of the asymmetry the reference config and
    // the filter docblock state, and the opposite of what the local-JWT filter does.
    $promoted = $this->get('/open/promote');
    $promoted->assertOk();

    $this->forgetSession();
    $this->followSession($promoted)->getJson('/whoami')
        ->assertOk()
        ->assertJson(['name' => 'root', 'authorities' => ['ROLE_ADMIN'], 'authenticated' => true]);

    // A bearer WAS presented: the filter established that bearer's context and cleared the holder in its own
    // `finally` on the way out — the controller's root with it — so nothing reached this second session.
    $this->forgetSession();
    $this->forgetCookies();
    $withBearer = $this->withHeader('Authorization', $this->bearerFor('svc-1'))->get('/open/promote');
    $withBearer->assertOk();

    $this->forgetSession();
    $this->flushHeaders()->forgetCookies()->followSession($withBearer)->getJson('/whoami')->assertStatus(401);
});
