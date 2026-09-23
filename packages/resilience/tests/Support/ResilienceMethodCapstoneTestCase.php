<?php

declare(strict_types=1);

namespace Firefly\Resilience\Tests\Support;

use Firefly\Data\DataServiceProvider;
use Firefly\Resilience\ResilienceServiceProvider;
use Firefly\Testing\FireflyTestCase;
use Illuminate\Support\ServiceProvider;

/**
 * The method-attribute capstone: firefly/data (which owns the proxy plan and the post-processor that applies
 * it) plus firefly/resilience, with scan paths that reach the annotated fixtures — so the plan is built by
 * the real UNCACHED path (every AdviceSource collected as a #[Component] through Container::getAll()), the
 * bean is wrapped by the real TransactionalBeanPostProcessor, and the link is the container's own #[Bean].
 * Nothing here constructs an interceptor or an invocation by hand: that is the unit test's job, and this one
 * exists precisely because a green unit test proves nothing about whether the bean is proxied at all.
 *
 * `firefly.cache.path` points at a directory holding nothing, for the reason
 * ProxiedMethodSecurityBootTestCase gives: every cachedFile() probe must answer null so the SCAN branch is
 * the one under test, never whatever an earlier test in this process happened to compile.
 *
 * The five instance blocks are all named `payments`, because PaymentService::charge() carries all six
 * attributes and every one of them resolves its policy by name at call time. Their values are chosen so the
 * only pattern that can change the outcome is the one being proved: a hundred tokens and five permits never
 * refuse, a thirty-second budget never expires, and `circuit-breaker.payments.failure-threshold => 1` makes
 * ONE failed attempt enough to trip the breaker — which is what turns "the retry gave up" into an assertion
 * about the breaker's state rather than a wait.
 */
abstract class ResilienceMethodCapstoneTestCase extends FireflyTestCase
{
    /** @return list<class-string<ServiceProvider>> */
    protected function fireflyProviders(): array
    {
        return [DataServiceProvider::class, ResilienceServiceProvider::class];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return [
            'cache.default' => 'array',
            'firefly.scan.paths' => ['Firefly\\Resilience\\Tests\\Fixtures\\'.$this->resilienceFixtureDir().'\\' => dirname(__DIR__).'/Fixtures/'.$this->resilienceFixtureDir()],
            'firefly.cache.path' => sys_get_temp_dir().'/firefly-resilience-method-uncached-'.bin2hex(random_bytes(6)),
            'firefly.resilience.method.enabled' => $this->resilienceMethodEnabled(),
            'firefly.resilience.retry.payments' => ['max-attempts' => 2, 'wait-duration' => '0s'],
            'firefly.resilience.circuit-breaker.payments' => ['failure-threshold' => 1],
            'firefly.resilience.rate-limiter.payments' => ['max-tokens' => 100, 'refill-rate' => 100.0],
            'firefly.resilience.bulkhead.payments' => ['max-concurrent' => 5],
            'firefly.resilience.time-limiter.payments' => ['timeout' => 30.0],
        ];
    }

    /**
     * The one fixture directory this boot scans, relative to tests/Fixtures — a hook rather than a literal
     * because every well-formed shape in this package lives in a directory of its own (so a happy-path boot
     * never walks into a refusal), and the capstone machinery around it is the same whichever one is under
     * test. Subclasses name theirs; nothing else about the boot changes.
     */
    protected function resilienceFixtureDir(): string
    {
        return 'Method';
    }

    /**
     * The gate under test — on here. It MUST be a separate boot rather than a post-boot config()->set(): the
     * key is read by the interceptor bean's #[ConditionalOnProperty] during condition filtering, so proving
     * "the proxy runs a pass-through in its place" needs the bean to have been conditioned away before the
     * bean was ever wrapped.
     */
    protected function resilienceMethodEnabled(): bool
    {
        return true;
    }
}
