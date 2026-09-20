<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Data\Proxy\ProxyClassGenerator;
use Firefly\Data\Proxy\ProxyFactory;
use Firefly\Data\Proxy\ProxyPlanner;
use Firefly\Data\Transaction\TransactionInterceptor;
use Firefly\Data\Transaction\TransactionTemplate;
use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Kernel\Exception\Security\AuthorizationException;
use Firefly\Security\Access\Expression\SecurityExpressionEvaluator;
use Firefly\Security\Access\Method\MethodSecurityAdviceSource;
use Firefly\Security\Access\Method\MethodSecurityEvaluator;
use Firefly\Security\Access\Method\MethodSecurityInterceptor;
use Firefly\Security\Access\RoleHierarchy;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Firefly\Security\Tests\Fixtures\Advice\OwnerPermissionEvaluator;
use Firefly\Security\Tests\Fixtures\Advice\Report;
use Firefly\Security\Tests\Fixtures\Advice\ReportService;
use Illuminate\Config\Repository;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

afterEach(fn () => SecurityContextHolder::clearContext());

/**
 * The proxy exactly as the uncached boot builds it: the security advice source alone (no #[Transactional]
 * here), rendered by the planner, generated and wrapped with the real interceptor. $enabled seeds the two
 * flags the interceptor reads live.
 */
function securedReportService(bool $enabled = true): ReportService
{
    $planner = new ProxyPlanner([new MethodSecurityAdviceSource]);
    $psr4 = ['Firefly\\Security\\Tests\\Fixtures\\Advice\\' => dirname(__DIR__, 2).'/Fixtures/Advice'];
    $methods = $planner->proxyMethods($planner->plan($psr4))[ReportService::class];
    $proxyClass = (new ProxyClassGenerator)->load(ReportService::class, $methods);

    $config = new Config(new Repository(['firefly' => ['security' => ['enabled' => $enabled, 'method' => ['enabled' => true]]]]));
    $interceptor = new MethodSecurityInterceptor(
        new MethodSecurityEvaluator(new SecurityExpressionEvaluator, RoleHierarchy::fromRules([]), new OwnerPermissionEvaluator),
        $config,
    );

    /** @var ReportService $proxy */
    $proxy = (new ProxyFactory)->wrap(new ReportService, ReportService::class, $proxyClass, new TransactionInterceptor(new TransactionTemplate), ['security' => $interceptor]);

    return $proxy;
}

function reportUser(string $name, string ...$authorities): void
{
    SecurityContextHolder::setContext(new SecurityContext(Authentication::authenticated($name, $name, array_values(array_map(
        static fn (string $a): SimpleGrantedAuthority => new SimpleGrantedAuthority($a),
        $authorities,
    )))));
}

it('refuses a #[PreAuthorize] on a plain service method through the proxy — 401 anonymous, 403 authenticated', function () {
    $service = securedReportService();

    expect($service)->toBeInstanceOf(ReportService::class)
        ->and($service::class)->not->toBe(ReportService::class)
        ->and(fn () => $service->totals())->toThrow(AuthenticationException::class);

    reportUser('ada', 'ROLE_USER');

    expect(fn () => $service->totals())->toThrow(AuthorizationException::class)
        ->and($service->unguarded())->toBe('open');

    reportUser('root', 'ROLE_ADMIN');

    expect($service->totals())->toBe(['total' => 42]);
});

it('refuses a returned object PostAuthorize does not grant, and narrows PostFilter and PreFilter results', function () {
    $service = securedReportService();
    reportUser('ada');

    expect($service->find(2))->toEqual(new Report(2, 'ada'))
        ->and(fn () => $service->find(1))->toThrow(AuthorizationException::class, 'That report belongs to someone else.')
        ->and($service->all())->toEqual([new Report(2, 'ada'), new Report(4, 'ada')])
        ->and($service->purge([1, 2, 3, 4], 'stale'))->toBe(['purged' => [2, 4], 'reason' => 'stale']);
});

it('is inert when the master flag is off, exactly like the dispatcher guard', function () {
    $service = securedReportService(enabled: false);

    expect($service->totals())->toBe(['total' => 42]);
});
