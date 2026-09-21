<?php

declare(strict_types=1);

namespace Firefly\Testing;

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Firefly\Testing\Attributes\FireflyTest;
use Firefly\Testing\Attributes\WithMockUser;
use Firefly\Testing\Security\ActingPrincipal;
use Firefly\Testing\Security\ActingPrincipalMiddleware;
use Firefly\Web\Security\AllowAllControllerSecurityGuard;
use Firefly\Web\Security\ControllerSecurityGuard;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Http\Kernel as HttpKernelContract;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Support\ServiceProvider;
use Illuminate\Testing\TestResponse;
use LogicException;
use Orchestra\Testbench\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Firefly testbench base. Absorbs the Family A boilerplate the ~14 hand-rolled *CapstoneTestCase
 * bases duplicated: AutoConfigure-first provider ordering, the eager config seed (the scan-at-register
 * rule), a filesystem-free log channel, the guarded app() accessor, and the TestResponse body quirk.
 * Subclasses override the three hooks (fireflyProviders/configOverrides/defineFireflyEnvironment) and
 * NOTHING else.
 *
 * It also carries the security test support (Spring Security's test module, as methods): actingAsPrincipal()
 * signs a principal in for the rest of the test, withoutSecurity() switches the stack off — the URL, CSRF and
 * authentication filters, the dispatcher guard, the proxy link and the CQRS bus authorizers — and a
 * #[WithMockUser] on the test class or method is honoured by setUp(). None of it needs firefly/security to be
 * among the providers — the holder is a static over Laravel's Context facade — so a plain web test can act as
 * someone too; when the security providers ARE booted, every filter, the dispatcher guard, the bus authorizers
 * and the proxied beans see the acting principal, because it is established before any of them run.
 */
abstract class FireflyTestCase extends TestCase
{
    /**
     * The Firefly capability providers this test needs, in registration order.
     * FireflyAutoConfigureServiceProvider is ALWAYS prepended by the harness — do NOT list it here.
     *
     * @return list<class-string<ServiceProvider>>
     */
    protected function fireflyProviders(): array
    {
        $attribute = $this->fireflyTestAttribute();

        return $attribute instanceof FireflyTest ? $attribute->providers : [];
    }

    /**
     * Eager, dot-keyed config seeded BEFORE boot so #[ConditionalOn*] passes (which scan at register
     * time) observe it. This is the seam for `firefly.scan.paths`, `firefly.<feature>.*` flags, etc.
     *
     * @return array<string, mixed>
     */
    protected function configOverrides(): array
    {
        $attribute = $this->fireflyTestAttribute();

        return $attribute instanceof FireflyTest ? $attribute->config : [];
    }

    /** Class-style analog of the two hooks above: a #[FireflyTest] attribute on the test class itself. */
    private function fireflyTestAttribute(): ?FireflyTest
    {
        $attrs = (new ReflectionClass(static::class))->getAttributes(FireflyTest::class);

        return $attrs === [] ? null : $attrs[0]->newInstance();
    }

    /**
     * Lazily-resolved bindings / manifest instances, bound AFTER config. Override to
     * $app->instance(SomeManifest::class, ...) or bind a port fake for the whole test class.
     */
    protected function defineFireflyEnvironment(Application $app): void
    {
        // no-op by default
    }

    /** @return list<class-string<ServiceProvider>> */
    protected function getPackageProviders($app): array
    {
        return [FireflyAutoConfigureServiceProvider::class, ...$this->fireflyProviders()];
    }

    protected function resolveApplicationConfiguration($app): void
    {
        parent::resolveApplicationConfiguration($app);

        /** @var Repository $config */
        $config = $app->make('config');

        // Filesystem-free logging so the read-only-sandbox createEmergencyLogger() path never throws.
        $config->set('logging.default', 'errorlog');
        $config->set('logging.channels.errorlog', ['driver' => 'errorlog', 'level' => 'debug']);

        // A FIXED APPLICATION KEY, because every real application has one — `key:generate` runs in the
        // skeleton's post-create-project-cmd — and a test app that does not is a test app that cannot
        // exercise anything touching the encrypter. That is not hypothetical: putting the admin dashboard
        // behind EncryptCookies (its routes had no CSRF protection at all) turned twenty-seven passing
        // tests into MissingAppKeyException, because the harness was the only place a LaraFly application
        // ever runs without a key. Fixed, not random, so a failure is reproducible from the output alone.
        if (! $config->has('app.key') || $config->get('app.key') === null || $config->get('app.key') === '') {
            // Exactly 32 bytes: aes-256-cbc, Laravel's default cipher, accepts nothing else.
            $config->set('app.key', 'base64:'.base64_encode(str_pad('firefly-testing-key', 32, '.')));
        }

        if (! $config->has('firefly')) {
            $config->set('firefly', []);
        }

        foreach ($this->configOverrides() as $key => $value) {
            $config->set($key, $value);
        }
    }

    protected function defineEnvironment($app): void
    {
        $this->defineFireflyEnvironment($app);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->applyWithMockUser();
    }

    /**
     * Run the rest of this test as a signed-in principal — for direct calls now, and for every HTTP request
     * through a middleware prepended to the kernel (Spring's @WithMockUser, as a method). $authorities are
     * authority strings (`ROLE_ADMIN`, `orders:read`); $principal defaults to the name.
     *
     * The holder is set immediately, so a proxied #[Service] or a bus handler called straight from the test
     * sees the principal; the ActingPrincipal is (re)bound in the container and the middleware prepended ONCE,
     * so a second call swaps who the next request is instead of stacking a second copy on the kernel. The
     * middleware is the outermost link on purpose: the persistence filter leaves an already authenticated holder
     * alone, and the framework filters that clear the holder in their own `finally` run inside it, so the
     * principal is back on the holder when the request returns.
     *
     * @param  list<string>  $authorities
     */
    public function actingAsPrincipal(string $name, array $authorities = [], mixed $principal = null): static
    {
        $authentication = Authentication::authenticated(
            $name,
            $principal ?? $name,
            array_map(static fn (string $authority): SimpleGrantedAuthority => new SimpleGrantedAuthority($authority), $authorities),
        );

        SecurityContextHolder::setContext(new SecurityContext($authentication));
        $this->app()->instance(ActingPrincipal::class, new ActingPrincipal($authentication));

        $kernel = $this->resolveHttpKernel();
        if ($kernel instanceof FoundationHttpKernel && ! $kernel->hasMiddleware(ActingPrincipalMiddleware::class)) {
            $kernel->prependMiddleware(ActingPrincipalMiddleware::class);
        }

        return $this;
    }

    /**
     * Typed `object` so the instanceof narrowing above is real — an application may bind another kernel to
     * the contract, and only Illuminate's has a global middleware stack to prepend to (the same shape as
     * SessionSecurityBootstrap::resolveHttpKernel() and FilterChainRegistrar::resolveHttpKernel()).
     */
    private function resolveHttpKernel(): object
    {
        return $this->app()->make(HttpKernelContract::class);
    }

    /**
     * Switch the security stack off for the rest of this test: the URL filter, the CSRF filter, every
     * authentication filter, the proxy's method-security link and the CQRS bus authorizers (through
     * MethodSecurityMessageEnforcer, which DefaultCommandBus/DefaultQueryBus hold by constructor) read their
     * flags live, and the dispatcher guard is rebound to the no-op default. Beans already built stay built;
     * only their gates change — so a controller test that dispatches a command whose handler carries a
     * #[PreAuthorize] gets the handler's answer, not a 401.
     */
    public function withoutSecurity(): static
    {
        /** @var Repository $config */
        $config = $this->app()->make('config');
        foreach (['firefly.security.enabled', 'firefly.security.http.enabled', 'firefly.security.csrf.enabled', 'firefly.security.method.enabled'] as $key) {
            $config->set($key, false);
        }

        $this->app()->instance(ControllerSecurityGuard::class, new AllowAllControllerSecurityGuard);

        return $this;
    }

    /** #[WithMockUser] on the test method (when the method exists on this class) beats the one on the class. */
    private function applyWithMockUser(): void
    {
        $attribute = null;

        if (method_exists($this, $this->name())) {
            $attrs = (new ReflectionMethod($this, $this->name()))->getAttributes(WithMockUser::class);
            $attribute = $attrs === [] ? null : $attrs[0]->newInstance();
        }
        if ($attribute === null) {
            $attrs = (new ReflectionClass(static::class))->getAttributes(WithMockUser::class);
            $attribute = $attrs === [] ? null : $attrs[0]->newInstance();
        }

        if ($attribute instanceof WithMockUser) {
            $this->actingAsPrincipal($attribute->name, $attribute->resolvedAuthorities());
        }
    }

    /** The booted testbench Application — guarded so a call before boot fails loud, not silently null. */
    public function app(): Application
    {
        if (! $this->app instanceof Application) {
            throw new LogicException('The application has not been booted yet — call this from within a test.');
        }

        return $this->app;
    }

    /** The booted Firefly ApplicationContext (the boot-engine facade). */
    public function fireflyContext(): ApplicationContext
    {
        /** @var ApplicationContext $context */
        $context = $this->app()->make(ApplicationContext::class);

        return $context;
    }

    /**
     * Read a TestResponse body via baseResponse->getContent(): TestResponse::streamedContent()
     * throws on a non-streamed response, so it is never used in these tests.
     *
     * @param  TestResponse<Response>  $response
     */
    public function responseBody(TestResponse $response): string
    {
        return (string) $response->baseResponse->getContent();
    }
}
