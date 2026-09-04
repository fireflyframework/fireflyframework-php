<?php

declare(strict_types=1);

namespace Firefly\Testing;

use Firefly\AutoConfigure\FireflyAutoConfigureServiceProvider;
use Firefly\Context\Boot\ApplicationContext;
use Firefly\Testing\Attributes\FireflyTest;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Illuminate\Testing\TestResponse;
use LogicException;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Firefly testbench base. Absorbs the Family A boilerplate the ~14 hand-rolled *CapstoneTestCase
 * bases duplicated: AutoConfigure-first provider ordering, the eager config seed (the scan-at-register
 * rule), a filesystem-free log channel, the guarded app() accessor, and the TestResponse body quirk.
 * Subclasses override the three hooks (fireflyProviders/configOverrides/defineFireflyEnvironment) and
 * NOTHING else.
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
        $attrs = (new \ReflectionClass(static::class))->getAttributes(FireflyTest::class);

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
