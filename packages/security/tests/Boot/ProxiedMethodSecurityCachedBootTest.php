<?php

declare(strict_types=1);

use Firefly\Context\Scan\AppScan;
use Firefly\Data\Proxy\ProxyPlan;
use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Kernel\Exception\Security\AuthorizationException;
use Firefly\Security\Access\Method\MethodSecurityAdviceSource;
use Firefly\Security\Access\Method\MethodSecurityInterceptor;
use Firefly\Security\Access\Method\SecurityMethodManifest;
use Firefly\Security\Access\PermissionEvaluator;
use Firefly\Security\Tests\Fixtures\Advice\OwnerPermissionEvaluator;
use Firefly\Security\Tests\Fixtures\Advice\Report;
use Firefly\Security\Tests\Fixtures\CachedAdvice\CachedReportService;
use Firefly\Security\Tests\Support\ProxiedMethodSecurityCachedBootTestCase;

uses(ProxiedMethodSecurityCachedBootTestCase::class);

/*
 | Method security on a stereotyped bean through the CACHED boot — the one production takes after firefly:cache.
 | ProxiedMethodSecurityBootTest proves the same enforcement on the scan path a developer's first `artisan serve`
 | takes; this file is the other half, because the two used to differ: the writer planned proxies from the
 | transactional advice alone, so a cached app compiled every rule into security-methods.php and then handed a
 | security-only #[Service] out bare. Nothing threw, nothing logged, and firefly.security.method.strict could
 | not see it — the manifest it checks for was present.
 */

it('loads the compiled proxy plan with the security advice instead of bridging a transactional-only one or scanning', function () {
    /** @var ProxiedMethodSecurityCachedBootTestCase $this */
    $context = $this->fireflyContext();
    $dir = ProxiedMethodSecurityCachedBootTestCase::compiled();

    // No scan roots, and every artefact the boot consults on disk: nothing can fall through to a scan.
    expect(AppScan::paths($this->app()))->toBe([])
        ->and(AppScan::cachedFile($this->app(), AppScan::PROXY_PLAN))->toBe($dir.'/'.AppScan::PROXY_PLAN)
        ->and(AppScan::cachedFile($this->app(), AppScan::SECURITY_METHODS))->toBe($dir.'/'.AppScan::SECURITY_METHODS)
        ->and(AppScan::cachedFile($this->app(), AppScan::TRANSACTIONAL))->toBe($dir.'/'.AppScan::TRANSACTIONAL);

    /** @var ProxyPlan $plan */
    $plan = $context->get(ProxyPlan::class);

    // The transactional.php bridge would have planned NOTHING here — the fixture carries no #[Transactional] —
    // so a plan naming the service with the security advice can only have been loaded from proxy-plan.php.
    expect($plan->toArray())->toBe(ProxyPlan::load($dir.'/'.AppScan::PROXY_PLAN)->toArray())
        ->and($plan->classes())->toBe([CachedReportService::class])
        ->and(array_keys($plan->adviceFor(CachedReportService::class)))->toBe([MethodSecurityAdviceSource::ID])
        ->and(array_keys($plan->methodsFor(CachedReportService::class)))->toBe(['all', 'find', 'purge', 'totals']);

    // And the rules came from the compiled manifest, the definitions from the compiled component manifest.
    /** @var SecurityMethodManifest $methods */
    $methods = $context->get(SecurityMethodManifest::class);
    expect($methods->ruleFor(CachedReportService::class, 'totals')?->expression)->toBe("hasRole('ADMIN')")
        ->and($context->get(PermissionEvaluator::class))->toBeInstanceOf(OwnerPermissionEvaluator::class);
});

it('hands out the proxy compiled into the cache directory, with the real interceptor bean in its security link', function () {
    /** @var ProxiedMethodSecurityCachedBootTestCase $this */
    $context = $this->fireflyContext();

    /** @var CachedReportService $reports */
    $reports = $context->get(CachedReportService::class);

    // realpath() on both sides: sys_get_temp_dir() is a symlink on macOS (/var -> /private/var) and PHP reports
    // a required file by its resolved path. The proxy was loaded from the cache's classmap — not generated
    // into a per-process directory, which is what the uncached ProxyMaterializer branch would have done.
    expect($reports::class)->toBe(CachedReportService::class.ProxyPlan::PROXY_SUFFIX)
        ->and((string) realpath((string) (new ReflectionClass($reports))->getFileName()))->toStartWith((string) realpath(ProxiedMethodSecurityCachedBootTestCase::compiled()).'/proxies/')
        ->and((string) file_get_contents((string) (new ReflectionClass($reports))->getFileName()))->toContain('__fireflySecurityInterceptor')
        ->and($context->has(MethodSecurityInterceptor::class))->toBeTrue();
});

it('enforces the pre rule, #[PostAuthorize] and the filters on the cached proxy exactly as the uncached boot does', function () {
    /** @var ProxiedMethodSecurityCachedBootTestCase $this */
    /** @var CachedReportService $reports */
    $reports = $this->fireflyContext()->get(CachedReportService::class);

    expect(fn () => $reports->totals())->toThrow(AuthenticationException::class);

    $this->signIn('ada', 'ROLE_USER');

    try {
        $reports->totals();
        throw new LogicException('not refused');
    } catch (AuthorizationException $e) {
        expect($e->errorCode())->toBe('ACCESS_DENIED');
    }

    expect($this->events->denials())->toHaveCount(1)
        ->and($this->events->denials()[0]->subject)->toBe(CachedReportService::class.'::totals')
        ->and($reports->find(2))->toEqual(new Report(2, 'ada'))
        ->and(fn () => $reports->find(1))->toThrow(AuthorizationException::class, 'That report belongs to someone else.')
        ->and($reports->all())->toEqual([new Report(2, 'ada'), new Report(4, 'ada')])
        ->and($reports->purge([1, 2, 3, 4], 'stale'))->toBe(['purged' => [2, 4], 'reason' => 'stale'])
        ->and($reports->unguarded())->toBe('open');

    $this->signIn('root', 'ROLE_ADMIN');

    expect($reports->totals())->toBe(['total' => 42]);
});
