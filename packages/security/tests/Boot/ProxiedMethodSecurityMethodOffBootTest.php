<?php

declare(strict_types=1);

use Firefly\Data\Proxy\ProxyPlan;
use Firefly\Security\Access\Method\MethodSecurityAdviceSource;
use Firefly\Security\Access\Method\MethodSecurityEvaluator;
use Firefly\Security\Access\Method\MethodSecurityInterceptor;
use Firefly\Security\Event\AuthenticationEventPublisher;
use Firefly\Security\Tests\Fixtures\Advice\Report;
use Firefly\Security\Tests\Fixtures\Advice\ReportService;
use Firefly\Security\Tests\Support\ProxiedMethodSecurityMethodOffBootTestCase;

uses(ProxiedMethodSecurityMethodOffBootTestCase::class);

/*
 | `firefly.security.method.enabled = false` with the master flag ON, through the real pipeline: the plan still
 | names the security advice (a compiled plan does not change with configuration), the proxy is still generated
 | and handed out with the security link, but the methodSecurityInterceptor #[Bean] is conditioned away — and
 | ONLY that bean: the evaluator and the publisher it needs are still there — so, because the advice declared
 | itself inert when unbound, the registry hands the proxy a PassThroughInterceptor and every call proceeds
 | straight to the method, anonymously and unfiltered.
 */
it('hands out the proxy with a pass-through in the security link when only the method flag is off', function () {
    /** @var ProxiedMethodSecurityMethodOffBootTestCase $this */
    $context = $this->fireflyContext();
    /** @var ProxyPlan $plan */
    $plan = $context->get(ProxyPlan::class);

    expect($this->app()->bound(MethodSecurityInterceptor::class))->toBeFalse()
        ->and($context->has(MethodSecurityInterceptor::class))->toBeFalse()
        ->and($context->has(MethodSecurityEvaluator::class))->toBeTrue()
        ->and($context->has(AuthenticationEventPublisher::class))->toBeTrue()
        ->and(array_keys($plan->adviceFor(ReportService::class)))->toBe([MethodSecurityAdviceSource::ID]);

    /** @var ReportService $reports */
    $reports = $context->get(ReportService::class);

    expect($reports::class)->toBe(ReportService::class.ProxyPlan::PROXY_SUFFIX)
        ->and($reports->totals())->toBe(['total' => 42])
        ->and($reports->find(1))->toEqual(new Report(1, 'bob'))
        ->and($reports->all())->toHaveCount(4)
        ->and($reports->purge([1, 2, 3, 4], 'stale'))->toBe(['purged' => [1, 2, 3, 4], 'reason' => 'stale'])
        ->and($this->events->denials())->toBe([]); // nothing refused: the unbound link never saw a call
});
