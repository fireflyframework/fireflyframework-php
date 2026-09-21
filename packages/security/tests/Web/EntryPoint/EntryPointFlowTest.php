<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Firefly\Security\SecurityServiceProvider;
use Firefly\Security\SecurityWiringProvider;
use Firefly\Security\Session\SessionSecurityContextRepository;
use Firefly\Security\Tests\Support\SecurityCapstoneTestCase;
use Firefly\Security\Tests\Support\SecurityFlows;
use Firefly\Security\Web\EntryPoint\AuthenticationEntryPoint;
use Firefly\Security\Web\EntryPoint\DelegatingAuthenticationEntryPoint;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\WebServiceProvider;
use Illuminate\Contracts\Validation\Factory;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Translation\ArrayLoader;
use Illuminate\Translation\Translator;
use Illuminate\Validation\Factory as IlluminateFactory;

/**
 * Form login on (its page is not built until Task 7 — only the redirect is measured here), plus an admin-only
 * area so an AUTHENTICATED refusal can be observed beside the anonymous ones. The fixture route stores a
 * ROLE_USER context the way FormLoginFilter will.
 */
abstract class EntryPointCapstoneTestCase extends SecurityCapstoneTestCase
{
    protected function securityOverrides(): array
    {
        return [
            'firefly.security.form_login.enabled' => true,
            'firefly.security.http.rules' => [
                ['pattern' => 'open', 'access' => 'permitAll'],
                ['pattern' => 'open/*', 'access' => 'permitAll'],
                ['pattern' => 'admin/*', 'access' => 'hasRole:ADMIN'],
                ['pattern' => 'api/*', 'access' => 'authenticated'],
                ['pattern' => '*', 'access' => 'authenticated'],
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Route::post('/open/sign-in-fixture', function (Request $request): array {
            (new SessionSecurityContextRepository)->save(new SecurityContext(Authentication::authenticated('ada', 'ada', [new SimpleGrantedAuthority('ROLE_USER')])), $request);

            return ['stored' => true];
        });
        Route::get('/admin/panel', static fn (): array => ['admin' => true]);
    }
}

uses(EntryPointCapstoneTestCase::class, SecurityFlows::class);

it('redirects a browser to the login page and answers a JSON client with a 401 problem', function () {
    /** @var EntryPointCapstoneTestCase $this */
    $this->get('/home')->assertRedirect('/login');

    $this->getJson('/api/orders')
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJson(['code' => 'AUTHENTICATION_FAILED']);

    // An api/* path is a machine surface whatever the Accept header says (firefly.web.error-page.json-paths).
    $this->get('/api/orders', ['Accept' => 'text/html'])->assertStatus(401);

    expect($this->events->denials())->toBe([]);
});

it('keeps deny-by-default and the 403 for an authenticated principal, publishing the denial', function () {
    /** @var EntryPointCapstoneTestCase $this */
    $this->getJson('/open')->assertOk();

    $signedIn = $this->post('/open/sign-in-fixture');
    $signedIn->assertOk();

    $this->forgetSession();
    $this->followSession($signedIn)->getJson('/whoami')->assertOk()->assertJson(['name' => 'ada']);

    $this->forgetSession();
    $this->followSession($signedIn)->getJson('/admin/panel')->assertStatus(403)->assertJson(['code' => 'ACCESS_DENIED']);

    expect($this->events->denials())->toHaveCount(1)
        ->and($this->events->denials()[0]->authentication->getName())->toBe('ada')
        ->and($this->events->denials()[0]->subject)->toBe('GET /admin/panel')
        ->and($this->events->denials()[0]->expression)->toBe("hasRole('ADMIN')");

    // Still a browser redirect for nobody, still a 403 page for somebody.
    $this->forgetSession();
    $this->forgetCookies();
    $this->get('/admin/panel')->assertRedirect('/login');
    $this->followSession($signedIn)->get('/admin/panel')->assertStatus(403);
});

/**
 * The real providers of web, cqrs and security over the bare harness — the raw Illuminate validation Factory is
 * bound by hand, as the actuator's real-provider boot does, because `needs: ['validation']` binds the Firefly
 * Validator INSTANCE and #[ConditionalOnMissingBean] reads the definition registry, not the container: the
 * validator() bean would still register and fail on the `validator` service the harness never has.
 *
 * @param  array<string,mixed>  $security  the `firefly.security.*` tree for this boot
 */
function bootEntryPointApp(array $security): Application
{
    return fireflyApplication(
        config: ['firefly' => ['cqrs' => [], 'security' => $security]],
        providers: [ValidationServiceProvider::class, WebServiceProvider::class, CqrsServiceProvider::class, CqrsWiringProvider::class, SecurityServiceProvider::class, SecurityWiringProvider::class],
        bindings: [Factory::class => new IlluminateFactory(new Translator(new ArrayLoader, 'en'))],
        needs: ['cache', 'http'],
    );
}

it('refuses entry_point=login without form login at boot', function () {
    expect(fn () => bootEntryPointApp(['enabled' => true, 'http' => ['enabled' => true, 'entry_point' => 'login']])->make(ApplicationContext::class))
        ->toThrow(ConfigurationException::class, 'entry_point');
});

it('refuses entry_point=login without form login even while the http surface is off, and an unknown mode', function () {
    // The bean that also validates the mode exists only under http.enabled; the wiring pass refuses regardless.
    expect(fn () => bootEntryPointApp(['enabled' => true, 'http' => ['enabled' => false, 'entry_point' => 'login']])->make(ApplicationContext::class))
        ->toThrow(ConfigurationException::class, 'entry_point')
        ->and(fn () => bootEntryPointApp(['enabled' => true, 'http' => ['enabled' => true, 'entry_point' => 'redirect']])->make(ApplicationContext::class))
        ->toThrow(ConfigurationException::class, 'redirect');
});

it('boots the same recipe under the default mode and binds the delegating entry point', function () {
    /** @var ApplicationContext $context */
    $context = bootEntryPointApp(['enabled' => true, 'http' => ['enabled' => true]])->make(ApplicationContext::class);

    expect($context->get(AuthenticationEntryPoint::class))->toBeInstanceOf(DelegatingAuthenticationEntryPoint::class);
});
