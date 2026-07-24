<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Container\Descriptor\ComponentDescriptor;
use Firefly\Container\Scope;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinition;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Security\Access\DenyAllPermissionEvaluator;
use Firefly\Security\Access\Expression\SecurityExpressionEvaluator;
use Firefly\Security\Access\HttpSecurity;
use Firefly\Security\Access\RoleHierarchy;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Jwt\JwtService;
use Firefly\Security\OAuth2\OAuth2ResourceServerFilter;
use Firefly\Security\Web\CsrfFilter;
use Firefly\Security\Web\HttpSecurityFilter;
use Firefly\Security\Web\JwtAuthenticationFilter;
use Firefly\Security\Web\SecurityHeadersFilter;
use Firefly\Web\Filter\CorrelationIdFilter;
use Firefly\Web\Filter\FilterChainRegistrar;
use Firefly\Web\Filter\RequestContextFilter;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

afterEach(fn () => SecurityContextHolder::clearContext());

function securityFilterDef(string $class, int $order): BeanDefinition
{
    return new BeanDefinition(new ComponentDescriptor(
        class: $class, stereotype: 'service', name: null, scope: Scope::Singleton,
        primary: false, order: $order, qualifier: null, interfaces: [], beans: [],
    ));
}

it('orders the security filters after the framework filters (risk #4)', function () {
    $registry = new BeanDefinitionRegistry;
    $registry->add(securityFilterDef(HttpSecurityFilter::class, -70));
    $registry->add(securityFilterDef(JwtAuthenticationFilter::class, -90));
    $registry->add(securityFilterDef(CsrfFilter::class, -80));
    $registry->add(securityFilterDef(SecurityHeadersFilter::class, -95));
    $registry->add(securityFilterDef(OAuth2ResourceServerFilter::class, -85));

    $config = new Config(new Repository([]));
    $context = new BootContext(
        container: new Container, definitions: $registry, config: $config,
        profiles: new Profiles([]), conditions: new ConditionEvaluator($config, new Profiles([])),
        report: new ConditionEvaluationReport,
    );

    expect((new FilterChainRegistrar)->orderedFilters($context))->toBe([
        RequestContextFilter::class,
        CorrelationIdFilter::class,
        SecurityHeadersFilter::class,
        JwtAuthenticationFilter::class,
        OAuth2ResourceServerFilter::class,
        CsrfFilter::class,
        HttpSecurityFilter::class,
    ]);
});

it('authenticates via JWT then authorizes via HttpSecurity end-to-end (risk #1/#4)', function () {
    $jwt = new JwtService(str_repeat('k', 40));
    // NOTE (brief-test fix): the brief's draft config omitted the master flag `firefly.security.enabled`.
    // HttpSecurityFilter::shouldNotFilter() requires BOTH the master flag AND the `http` surface flag
    // (surface flags require master — see docs/modules/security.md config table); without it the filter
    // is inert and the anonymous request below would fall through to the controller instead of 401'ing.
    $config = new Config(new Repository(['firefly' => ['security' => ['enabled' => true, 'jwt' => ['enabled' => true], 'http' => ['enabled' => true]]]]));

    $authFilter = new JwtAuthenticationFilter($jwt, $config);
    $rules = HttpSecurity::create()->requestMatcher('admin/*')->hasRole('ADMIN')->anyRequest()->authenticated();
    $httpFilter = new HttpSecurityFilter($rules, new SecurityExpressionEvaluator, RoleHierarchy::fromRules([]), new DenyAllPermissionEvaluator, $config);

    $adminToken = $jwt->encode(['sub' => 'root', 'authorities' => ['ROLE_ADMIN']], 3600);
    $request = Request::create('/admin/users', 'GET');
    $request->headers->set('Authorization', 'Bearer '.$adminToken);

    // Compose: auth filter → http filter → controller.
    $out = $authFilter->handle($request, fn (Request $r) => $httpFilter->handle($r, fn () => new Response('ok')));
    expect($out)->toBeInstanceOf(Response::class);

    // Anonymous request to the same protected path is a 401.
    expect(fn () => $authFilter->handle(Request::create('/admin/users', 'GET'), fn (Request $r) => $httpFilter->handle($r, fn () => new Response('ok'))))
        ->toThrow(AuthenticationException::class);
});
