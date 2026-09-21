<?php

declare(strict_types=1);

use Firefly\Cqrs\Command\CommandBus;
use Firefly\Cqrs\Exception\CommandProcessingException;
use Firefly\Data\Proxy\ProxyPlan;
use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Kernel\Exception\Security\AuthorizationException;
use Firefly\Security\Access\Method\MethodSecurityAdviceSource;
use Firefly\Security\Access\Method\MethodSecurityEvaluator;
use Firefly\Security\Access\Method\MethodSecurityInterceptor;
use Firefly\Security\Access\PermissionEvaluator;
use Firefly\Security\Event\AuthenticationEventPublisher;
use Firefly\Security\Tests\Fixtures\Advice\ArchiveCommand;
use Firefly\Security\Tests\Fixtures\Advice\ArchiveHandler;
use Firefly\Security\Tests\Fixtures\Advice\OwnedReportService;
use Firefly\Security\Tests\Fixtures\Advice\OwnerPermissionEvaluator;
use Firefly\Security\Tests\Fixtures\Advice\Report;
use Firefly\Security\Tests\Fixtures\Advice\ReportController;
use Firefly\Security\Tests\Fixtures\Advice\ReportService;
use Firefly\Security\Tests\Support\ProxiedMethodSecurityBootTestCase;

uses(ProxiedMethodSecurityBootTestCase::class);

/*
 | Method security on ANY stereotyped bean, through the real pipeline: the boot here is the one a developer's
 | first `artisan serve` takes (see the base class), and the only thing bound by hand is the event recorder.
 | Everything below used to be proven only by hand-wiring ProxyPlanner -> ProxyClassGenerator -> ProxyFactory
 | around a `new MethodSecurityInterceptor(...)`, which says nothing about whether the shipped manifests, bean
 | conditions and post-processor actually deliver that interceptor to a #[Service] — and a fail-open there is
 | exactly the defect this package treats as the worst kind.
 */

it('plans the security advice for the stereotyped services from the scanned AdviceSource beans, and leaves the controller and the pre-only handler to their seams', function () {
    /** @var ProxiedMethodSecurityBootTestCase $this */
    /** @var ProxyPlan $plan */
    $plan = $this->fireflyContext()->get(ProxyPlan::class);

    // MethodSecurityAdviceSource entered the plan through Container::getAll(AdviceSource::class): that is the
    // shipped components manifest row declaring the interface, not anything this test bound.
    expect($plan->hasProxyFor(ReportService::class))->toBeTrue()
        ->and($plan->hasProxyFor(OwnedReportService::class))->toBeTrue()
        ->and(array_keys($plan->adviceFor(ReportService::class)))->toBe([MethodSecurityAdviceSource::ID])
        ->and(array_keys($plan->methodsFor(ReportService::class)))->toBe(['all', 'archive', 'find', 'purge', 'totals'])
        ->and($plan->hasProxyFor(ReportController::class))->toBeFalse()
        ->and($plan->hasProxyFor(ArchiveHandler::class))->toBeFalse();
});

it('hands out the generated proxy for a plain #[Service] with the real interceptor bean in its security link', function () {
    /** @var ProxiedMethodSecurityBootTestCase $this */
    $context = $this->fireflyContext();

    /** @var ReportService $reports */
    $reports = $context->get(ReportService::class);

    // The materialised proxy, not the bare class — and the link is the container's own #[Bean], which exists
    // only because both flags are on (the master flag also conditions the evaluator and the publisher it needs).
    expect($reports::class)->toBe(ReportService::class.ProxyPlan::PROXY_SUFFIX)
        ->and($reports)->toBeInstanceOf(ReportService::class)
        ->and($context->get(OwnedReportService::class)::class)->toBe(OwnedReportService::class.ProxyPlan::PROXY_SUFFIX)
        ->and($context->has(MethodSecurityInterceptor::class))->toBeTrue()
        ->and($context->get(MethodSecurityInterceptor::class))->toBeInstanceOf(MethodSecurityInterceptor::class)
        ->and($context->has(MethodSecurityEvaluator::class))->toBeTrue()
        ->and($context->has(AuthenticationEventPublisher::class))->toBeTrue()
        // The application's own PermissionEvaluator definition displaced the deny-all default.
        ->and($context->get(PermissionEvaluator::class))->toBeInstanceOf(OwnerPermissionEvaluator::class);
});

it('refuses a #[PreAuthorize] on the proxied service — 401 anonymous, 403 for the wrong role, allowed for the right one', function () {
    /** @var ProxiedMethodSecurityBootTestCase $this */
    /** @var ReportService $reports */
    $reports = $this->fireflyContext()->get(ReportService::class);

    // Anonymous: "authenticate first", and no authorization decision was made, so nothing is published.
    expect(fn () => $reports->totals())->toThrow(AuthenticationException::class)
        ->and($this->events->denials())->toBe([]);

    $this->signIn('ada', 'ROLE_USER');

    try {
        $reports->totals();
        throw new LogicException('not refused');
    } catch (AuthorizationException $e) {
        expect($e->errorCode())->toBe('ACCESS_DENIED');
    }

    // The denial reached the recorder bound as ApplicationEventPublisher: the interceptor's evaluator was built
    // with the REAL (non-nullable) AuthenticationEventPublisher bean, named Class::method and the rule.
    expect($this->events->denials())->toHaveCount(1)
        ->and($this->events->denials()[0]->authentication->getName())->toBe('ada')
        ->and($this->events->denials()[0]->subject)->toBe(ReportService::class.'::totals')
        ->and($this->events->denials()[0]->expression)->toBe("hasRole('ADMIN')")
        ->and($reports->unguarded())->toBe('open');

    $this->signIn('root', 'ROLE_ADMIN');

    expect($reports->totals())->toBe(['total' => 42]);
});

it('enforces #[PostAuthorize], #[PostFilter] and #[PreFilter] on the proxied service against the application PermissionEvaluator', function () {
    /** @var ProxiedMethodSecurityBootTestCase $this */
    /** @var ReportService $reports */
    $reports = $this->fireflyContext()->get(ReportService::class);
    $this->signIn('ada');

    expect($reports->find(2))->toEqual(new Report(2, 'ada'));

    try {
        $reports->find(1);
        throw new LogicException('not refused');
    } catch (AuthorizationException $e) {
        // The rule's own code and sentence, so the client learns what was refused without the class name.
        expect($e->errorCode())->toBe('REPORT_NOT_YOURS')
            ->and($e->getMessage())->toBe('That report belongs to someone else.');
    }

    // Narrowed, not blanket-denied: ada keeps her even-numbered reports and loses bob's.
    expect($reports->all())->toEqual([new Report(2, 'ada'), new Report(4, 'ada')])
        ->and($reports->purge([1, 2, 3, 4], 'stale'))->toBe(['purged' => [2, 4], 'reason' => 'stale'])
        ->and($reports->archive('stale', [1, 2, 3, 4]))->toBe(['archived' => [2, 4], 'reason' => 'stale'])
        ->and($this->events->denials())->toHaveCount(1)
        ->and($this->events->denials()[0]->subject)->toBe(ReportService::class.'::find');
});

it('applies a class-level #[PostAuthorize] to every proxied method and lets a method-level one replace it', function () {
    /** @var ProxiedMethodSecurityBootTestCase $this */
    /** @var OwnedReportService $owned */
    $owned = $this->fireflyContext()->get(OwnedReportService::class);
    $this->signIn('ada');

    expect($owned->find(2))->toEqual(new Report(2, 'ada'))
        ->and(fn () => $owned->find(1))->toThrow(AuthorizationException::class, 'That report belongs to someone else.')
        // latest() replaced the class rule with permitAll(): bob's report comes back to ada.
        ->and($owned->latest())->toEqual(new Report(1, 'bob'));
});

it('still enforces the pre-only handler rule at the bus, which is why the plan left the handler unproxied', function () {
    /** @var ProxiedMethodSecurityBootTestCase $this */
    $context = $this->fireflyContext();
    /** @var CommandBus $commands */
    $commands = $context->get(CommandBus::class);

    // The handler is final and pre-only: no proxy, the bare class — and the bus seam answers exactly as the
    // proxy seam does, 401 for nobody and 403 for the wrong role.
    expect($context->get(ArchiveHandler::class)::class)->toBe(ArchiveHandler::class);

    try {
        $commands->send(new ArchiveCommand(7));
        throw new LogicException('not refused');
    } catch (CommandProcessingException $e) {
        expect($e->getPrevious())->toBeInstanceOf(AuthenticationException::class);
    }

    $this->signIn('ada', 'ROLE_USER');

    try {
        $commands->send(new ArchiveCommand(7));
        throw new LogicException('not refused');
    } catch (CommandProcessingException $e) {
        expect($e->getPrevious())->toBeInstanceOf(AuthorizationException::class);
    }

    $this->signIn('root', 'ROLE_ADMIN');

    expect($commands->send(new ArchiveCommand(7)))->toBe('archived:7');
});
