<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Context\Boot\BootContext;
use Firefly\Context\Condition\ConditionEvaluationReport;
use Firefly\Context\Condition\ConditionEvaluator;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Security\Boot\SecurityWiringPass;
use Firefly\Security\Jwt\JwtService;
use Firefly\Security\Jwt\WeakSigningSecretException;
use Firefly\Web\Security\ControllerSecurityGuard;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

/**
 * Unit-level tests of SecurityWiringPass::run() in ISOLATION from the full boot pipeline — no
 * EagerSingletonsPass, no ComponentScan, no #[Bean] auto-configuration. This is deliberate: in a
 * full app boot, JwtService's own #[Bean] (SecurityAutoConfiguration::jwtService(), gated only by
 * firefly.security.jwt.enabled, non-#[Lazy]) is ALREADY resolved eagerly by EagerSingletonsPass
 * (phase 900) — strictly BEFORE WiringPasses (phase 1000) even starts, independent of the master
 * flag. That earlier mechanism means a full-stack RealProviderBootTest-style boot throws the
 * weak-secret exception regardless of whether SecurityWiringPass's OWN eager-resolve is hoisted
 * above or left below the master-flag early-return — it cannot, by itself, discriminate the fix.
 * These tests call SecurityWiringPass::run() directly against a bare Container with NOTHING
 * pre-resolved, isolating exactly the ordering this pass is responsible for.
 */
/**
 * @param  array<string,mixed>  $security  the `firefly.security.*` tree for this pass invocation
 */
function securityWiringContext(Container $container, array $security): BootContext
{
    $config = new Config(new Repository(['firefly' => ['security' => $security]]));
    $profiles = new Profiles([]);

    return new BootContext(
        container: $container,
        definitions: new BeanDefinitionRegistry,
        config: $config,
        profiles: $profiles,
        conditions: new ConditionEvaluator($config, $profiles),
        report: new ConditionEvaluationReport,
    );
}

it('fails fast on a weak JWT secret even when the master flag is off', function () {
    $container = new Container;
    // A minimal stand-in for the #[Bean] factory: JwtService's real constructor takes only
    // Config-derived scalars, so a direct binding is a faithful proxy for SecurityAutoConfiguration
    // ::jwtService() without pulling in the whole AutoConfigure/ComponentScan pipeline.
    $container->bind(JwtService::class, fn () => new JwtService('changeme'));

    $context = securityWiringContext($container, [
        'enabled' => false,
        'jwt' => ['enabled' => true],
    ]);

    expect(fn () => (new SecurityWiringPass)->run($context))->toThrow(WeakSigningSecretException::class);
});

it('does not throw and does not touch ControllerSecurityGuard when both jwt and the master flag are off', function () {
    $container = new Container;
    $context = securityWiringContext($container, [
        'enabled' => false,
        'jwt' => ['enabled' => false],
    ]);

    (new SecurityWiringPass)->run($context);

    expect($container->bound(ControllerSecurityGuard::class))->toBeFalse();
});
