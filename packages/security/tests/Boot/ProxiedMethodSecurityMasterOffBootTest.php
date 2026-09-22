<?php

declare(strict_types=1);

use Firefly\Data\Proxy\ProxyPlan;
use Firefly\Security\Access\Method\MethodSecurityAdviceSource;
use Firefly\Security\Access\Method\MethodSecurityEvaluator;
use Firefly\Security\Access\Method\MethodSecurityInterceptor;
use Firefly\Security\Event\AuthenticationEventPublisher;
use Firefly\Security\Tests\Fixtures\Advice\Report;
use Firefly\Security\Tests\Fixtures\Advice\ReportService;
use Firefly\Security\Tests\Support\ProxiedMethodSecurityMasterOffBootTestCase;

uses(ProxiedMethodSecurityMasterOffBootTestCase::class);

/*
 | `firefly.security.enabled = false`, through the real pipeline: the "annotations are inert until security is
 | enabled" rule the controller guard has always had, now for a proxied #[Service]. No security bean exists at
 | all, the plan and the proxy are unchanged, and the registry degrades the security link to a pass-through
 | instead of refusing to boot — because MethodSecurityAdviceSource declared the advice inert when unbound.
 */
it('hands out the proxy with a pass-through in the security link when the master flag is off', function () {
    /** @var ProxiedMethodSecurityMasterOffBootTestCase $this */
    $context = $this->fireflyContext();
    /** @var ProxyPlan $plan */
    $plan = $context->get(ProxyPlan::class);

    expect($this->app()->bound(MethodSecurityInterceptor::class))->toBeFalse()
        ->and($context->has(MethodSecurityInterceptor::class))->toBeFalse()
        ->and($context->has(MethodSecurityEvaluator::class))->toBeFalse()
        ->and($context->has(AuthenticationEventPublisher::class))->toBeFalse()
        ->and(array_keys($plan->adviceFor(ReportService::class)))->toBe([MethodSecurityAdviceSource::ID]);

    /** @var ReportService $reports */
    $reports = $context->get(ReportService::class);

    expect($reports::class)->toBe(ReportService::class.ProxyPlan::PROXY_SUFFIX)
        ->and($reports->totals())->toBe(['total' => 42])
        ->and($reports->find(1))->toEqual(new Report(1, 'bob'))
        ->and($reports->purge([1, 2, 3, 4], 'stale'))->toBe(['purged' => [1, 2, 3, 4], 'reason' => 'stale'])
        ->and($this->events->denials())->toBe([]);
});
