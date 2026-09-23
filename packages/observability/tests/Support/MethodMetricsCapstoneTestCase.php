<?php

declare(strict_types=1);

namespace Firefly\Observability\Tests\Support;

use Firefly\Data\DataServiceProvider;
use Firefly\Observability\Tests\Fixtures\Method\TimedService;

/**
 * The method-attribute capstone: the SAME provider set ObservabilityCapstoneTestCase boots, plus firefly/data
 * (which owns the proxy plan and the post-processor that applies it) and scan paths that reach the annotated
 * fixtures — so the plan is built by the real UNCACHED path (every AdviceSource collected as a #[Component]
 * through Container::getAll()), the bean is wrapped by the real TransactionalBeanPostProcessor, the link is
 * the container's own #[Bean], and the meter is read back off the real /actuator/prometheus scrape. Nothing
 * here constructs an interceptor or an invocation by hand — that is the unit test's job, and this one exists
 * precisely because a green unit test proved nothing about whether the bean is proxied at all.
 *
 * `firefly.cache.path` points at a directory holding nothing, for the reason
 * ProxiedMethodSecurityBootTestCase gives: every cachedFile() probe must answer null so the SCAN branch is
 * the one under test, never whatever an earlier test in this process happened to compile.
 */
abstract class MethodMetricsCapstoneTestCase extends ObservabilityCapstoneTestCase
{
    protected function fireflyProviders(): array
    {
        return [...parent::fireflyProviders(), DataServiceProvider::class];
    }

    protected function configOverrides(): array
    {
        return [
            ...parent::configOverrides(),
            'firefly.scan.paths' => ['Firefly\\Observability\\Tests\\Fixtures\\Method\\' => dirname(__DIR__).'/Fixtures/Method'],
            'firefly.cache.path' => sys_get_temp_dir().'/firefly-observability-method-uncached-'.bin2hex(random_bytes(6)),
            'firefly.observability.method.enabled' => $this->methodMetricsEnabled(),
        ];
    }

    /**
     * The gate under test — on here; the disabled sibling returns false. It MUST be a separate boot rather
     * than a post-boot config()->set(): the key is read by the interceptor bean's #[ConditionalOnProperty]
     * during condition filtering, and proving "the proxy runs a pass-through in its place" needs the bean to
     * have been conditioned away before the bean was ever wrapped.
     */
    protected function methodMetricsEnabled(): bool
    {
        return true;
    }

    public function timedService(): TimedService
    {
        /** @var TimedService $service */
        $service = $this->app()->make(TimedService::class);

        return $service;
    }
}
