<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Support;

use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\Data\DataServiceProvider;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Firefly\Security\SecurityServiceProvider;
use Firefly\Security\SecurityWiringProvider;
use Firefly\Testing\Double\RecordingAuthenticationEvents;
use Firefly\Testing\FireflyTestCase;
use Firefly\Validation\ValidationServiceProvider;
use Firefly\Web\WebServiceProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Boots the REAL kernel with the shipped Validation, Web, Data, Cqrs and Security providers on the UNCACHED path —
 * `firefly.scan.paths` pointed at tests/Fixtures/Advice and `firefly.cache.path` at a directory holding nothing —
 * which is the boot a developer's first `artisan serve` takes. Nothing is compiled by hand and nothing is bound by
 * hand except the RecordingAuthenticationEvents instance (the ApplicationEventPublisher port, so the test can read
 * the denials the interceptor publishes): the app scan registers ReportService, OwnedReportService and
 * AdviceSecurityConfiguration as definitions; DataAutoConfiguration::proxyPlan() collects MethodSecurityAdviceSource
 * beside Data's own TransactionalAdviceSource through Container::getAll() (which depends on the shipped components
 * manifest row declaring the AdviceSource interface); ProxyMaterializer generates the security-only proxy
 * in-process; TransactionalBeanPostProcessor hands it out for a plain #[Service]; and InterceptorRegistry resolves
 * the methodSecurityInterceptor #[Bean] — or a PassThroughInterceptor when a flag has conditioned it away — when
 * the container builds the bean. The unit-level MethodSecurityInterceptorTest drives the same classes through
 * ProxyFactory directly; this base is the pipeline half, so a regression in the bean conditions, the manifest row
 * or the post-processor turns red here instead of silently making every service-level rule a no-op.
 *
 * Subclasses vary only security(): the `firefly.security.*` tree the boot is seeded with.
 */
abstract class ProxiedMethodSecurityBootTestCase extends FireflyTestCase
{
    public RecordingAuthenticationEvents $events;

    /** @return array<string, mixed> the `firefly.security.*` tree for this boot */
    protected function security(): array
    {
        return ['enabled' => true];
    }

    /** @return list<class-string<ServiceProvider>> */
    protected function fireflyProviders(): array
    {
        return [
            ValidationServiceProvider::class, // WebServiceProvider's BeanValidator needs the Validator port
            WebServiceProvider::class,
            DataServiceProvider::class,
            CqrsServiceProvider::class,
            CqrsWiringProvider::class,
            SecurityServiceProvider::class,
            SecurityWiringProvider::class,
        ];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            'firefly.scan.paths' => ['Firefly\\Security\\Tests\\Fixtures\\Advice\\' => dirname(__DIR__).'/Fixtures/Advice'],
            // A directory that holds no artifact, so every cachedFile() probe answers null and the scan branch
            // is the one under test — never whatever an earlier test in this process happened to compile.
            'firefly.cache.path' => sys_get_temp_dir().'/firefly-security-proxy-uncached-'.bin2hex(random_bytes(6)),
            'firefly.security' => $this->security(),
        ];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        // Bound BEFORE boot so the master-gated AuthenticationEventPublisher bean wraps it; the framework's own
        // DispatcherEventPublisher is a bound()-guarded provider binding, which is why an instance() wins here.
        $this->events = new RecordingAuthenticationEvents;
        $app->instance(ApplicationEventPublisher::class, $this->events);
    }

    protected function tearDown(): void
    {
        // The holder is Context-facade backed and request-scoped: never let one test's principal reach the next.
        SecurityContextHolder::clearContext();

        parent::tearDown();
    }

    /** Signs a principal into the holder the interceptor reads on every call. */
    public function signIn(string $name, string ...$authorities): void
    {
        SecurityContextHolder::setContext(new SecurityContext(Authentication::authenticated($name, $name, array_values(array_map(
            static fn (string $authority): SimpleGrantedAuthority => new SimpleGrantedAuthority($authority),
            $authorities,
        )))));
    }
}
