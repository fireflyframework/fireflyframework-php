<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Container\Attributes\Order;
use Firefly\Cqrs\CqrsServiceProvider;
use Firefly\Cqrs\CqrsWiringProvider;
use Firefly\FeatureFlags\Context\ApplicationEvaluationContextContributor;
use Firefly\FeatureFlags\Context\EvaluationContextBuilder;
use Firefly\FeatureFlags\Context\EvaluationContextContributor;
use Firefly\FeatureFlags\Context\EvaluationContextResolver;
use Firefly\FeatureFlags\Evaluation\FlagdEvaluator;
use Firefly\FeatureFlags\FeatureFlagsServiceProvider;
use Firefly\FeatureFlags\FeatureFlagsWiringProvider;
use Firefly\FeatureFlags\Tests\Support\StaticFlagdEvaluator;
use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Firefly\Security\FeatureFlags\PrincipalEvaluationContextContributor;
use Firefly\Security\SecurityServiceProvider;
use Firefly\Security\SecurityWiringProvider;
use Illuminate\Config\Repository;
use Orchestra\Testbench\TestCase;

uses(TestCase::class);

afterEach(fn () => SecurityContextHolder::clearContext());

/** @param array<string, mixed> $flags */
function featureFlagsSecurityContributor(array $flags = []): PrincipalEvaluationContextContributor
{
    return new PrincipalEvaluationContextContributor(new Config(new Repository(['firefly' => ['feature-flags' => $flags]])));
}

/** @param array<string, mixed> $flags */
function featureFlagsSecurityContext(array $flags = []): EvaluationContextBuilder
{
    $context = new EvaluationContextBuilder;
    featureFlagsSecurityContributor($flags)->contribute($context);

    return $context;
}

/** @param class-string $class */
function featureFlagsSecurityOrderOf(string $class): int
{
    $orders = (new ReflectionClass($class))->getAttributes(Order::class);

    return $orders === [] ? 0 : $orders[0]->newInstance()->order;
}

it('contributes the principal as targeting key, roles without ROLE_ and the tenant attribute', function (): void {
    SecurityContextHolder::setContext(new SecurityContext(Authentication::authenticated('ada', 'ada', [
        new SimpleGrantedAuthority('ROLE_BETA'), new SimpleGrantedAuthority('ROLE_STAFF'), new SimpleGrantedAuthority('SCOPE_orders:read'),
    ], ['tenant' => 'acme', 'org' => 'globex'])));

    $context = featureFlagsSecurityContext();

    expect($context->targetingKey())->toBe('ada')
        ->and($context->get('roles'))->toBe(['BETA', 'STAFF'])
        ->and($context->get('tenant'))->toBe('acme');
});

it('reads the tenant from the attribute firefly.feature-flags.context.tenant-attribute names', function (): void {
    SecurityContextHolder::setContext(new SecurityContext(Authentication::authenticated('ada', 'ada', [], ['org' => 'globex'])));

    expect(featureFlagsSecurityContext(['context' => ['tenant-attribute' => 'org']])->get('tenant'))->toBe('globex');
});

it('reads an int tenant as text and leaves the tenant out when the principal has none', function (): void {
    SecurityContextHolder::setContext(new SecurityContext(Authentication::authenticated('ada', 'ada', [], ['tenant' => 7])));
    $numbered = featureFlagsSecurityContext();

    SecurityContextHolder::setContext(new SecurityContext(Authentication::authenticated('grace', 'grace', [], ['tenant' => ['acme']])));
    $listed = featureFlagsSecurityContext();

    SecurityContextHolder::setContext(new SecurityContext(Authentication::authenticated('linus', 'linus', [])));
    $none = featureFlagsSecurityContext();

    expect($numbered->get('tenant'))->toBe('7')
        ->and($listed->has('tenant'))->toBeFalse()
        ->and($none->has('tenant'))->toBeFalse()
        ->and($none->targetingKey())->toBe('linus')
        ->and($none->attributes())->toBe(['roles' => []]);
});

it('contributes nothing for anonymous traffic', function (): void {
    $context = featureFlagsSecurityContext();

    expect($context->targetingKey())->toBeNull()
        ->and($context->attributes())->toBe([]);
});

it('contributes nothing for a token that is not authenticated', function (): void {
    SecurityContextHolder::setContext(new SecurityContext(Authentication::unauthenticated('ada', 'ada', 'secret')));

    $context = featureFlagsSecurityContext();

    expect($context->targetingKey())->toBeNull()
        ->and($context->attributes())->toBe([]);
});

it('runs after the process attributes and before the application\'s contributors, which may override it', function (): void {
    SecurityContextHolder::setContext(new SecurityContext(Authentication::authenticated('ada', 'ada', [new SimpleGrantedAuthority('ROLE_BETA')], ['tenant' => 'acme'])));
    $application = new class implements EvaluationContextContributor
    {
        public function contribute(EvaluationContextBuilder $context): void
        {
            $context->set('tenant', 'acme-eu');
        }
    };
    $contributors = [$application, featureFlagsSecurityContributor()];
    // Container::getAll() sorts the collected contributors by #[Order] ascending, a missing #[Order] being 0.
    usort($contributors, static fn (object $a, object $b): int => featureFlagsSecurityOrderOf($a::class) <=> featureFlagsSecurityOrderOf($b::class));

    $resolved = (new EvaluationContextResolver($contributors))->resolve();

    expect(featureFlagsSecurityOrderOf(PrincipalEvaluationContextContributor::class))->toBe(-100)
        ->and(featureFlagsSecurityOrderOf(ApplicationEvaluationContextContributor::class))->toBeLessThan(-100)
        ->and($resolved->getTargetingKey())->toBe('ada')
        ->and($resolved->getAttributes()->toArray())->toBe(['roles' => ['BETA'], 'tenant' => 'acme-eu']);
});

it('is collected after the process attributes only while security and feature flags are both on', function (): void {
    $contributors = static function (bool $security, bool $flags): array {
        $context = bootFireflyApp(
            ['firefly' => ['cqrs' => [], 'security' => ['enabled' => $security], 'feature-flags' => ['enabled' => $flags]]],
            [CqrsServiceProvider::class, CqrsWiringProvider::class, SecurityServiceProvider::class, SecurityWiringProvider::class, FeatureFlagsServiceProvider::class, FeatureFlagsWiringProvider::class],
            [FlagdEvaluator::class => new StaticFlagdEvaluator],
            needs: ['cache'],
        );

        return array_map(static fn (object $contributor): string => $contributor::class, $context->getAll(EvaluationContextContributor::class));
    };

    expect($contributors(true, true))->toBe([ApplicationEvaluationContextContributor::class, PrincipalEvaluationContextContributor::class])
        ->and($contributors(false, true))->toBe([ApplicationEvaluationContextContributor::class])
        ->and($contributors(true, false))->toBe([]);
});
