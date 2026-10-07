<?php

declare(strict_types=1);

namespace Firefly\Security\FeatureFlags;

use Firefly\Config\Config;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnClass;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\FeatureFlags\Context\EvaluationContextBuilder;
use Firefly\FeatureFlags\Context\EvaluationContextContributor;
use Firefly\Security\Core\SecurityContextHolder;

/**
 * firefly/security's answer to firefly/feature-flags' question: who is evaluating (CONTRACT.md "Evaluation
 * context"). The authenticated principal's name becomes the targeting key, its ROLE_ authorities the `roles`
 * attribute (prefix stripped — `hasRole:BETA` and a flag rule `{"in": ["BETA", {"var": "roles"}]}` read the same
 * name; other authorities, such as SCOPE_, are not roles), and the principal attribute named by
 * firefly.feature-flags.context.tenant-attribute (default `tenant`) the `tenant`, when it is a string or an int
 * (read as text). Anonymous traffic — no authentication, or a token that is not authenticated — contributes
 * nothing, so a percentage rollout gives it the default variant.
 *
 * A #[Component] (collected through Container::getAll), not a #[Bean]; #[Order(-100)] runs it after the
 * application/profile contributor (-200) and before every application contributor (0 unless ordered), which may
 * override what it set. The conditions are the MethodSecurityRequirementContributor shape: an application without
 * firefly/feature-flags (a `suggest` of this package) never loads a class implementing an absent interface, and one
 * with either master flag off registers nothing. The direction is Security -> FeatureFlags: nothing may depend on
 * Security.
 */
#[Component]
#[Order(-100)]
#[ConditionalOnClass(EvaluationContextContributor::class)]
#[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
#[ConditionalOnProperty(name: 'firefly.feature-flags.enabled', havingValue: 'true')]
final class PrincipalEvaluationContextContributor implements EvaluationContextContributor
{
    private const string ROLE_PREFIX = 'ROLE_';

    public function __construct(private readonly Config $config) {}

    public function contribute(EvaluationContextBuilder $context): void
    {
        $authentication = SecurityContextHolder::getAuthentication();
        if ($authentication === null || ! $authentication->isAuthenticated()) {
            return;
        }

        $context->setTargetingKey($authentication->getName());

        $roles = [];
        foreach ($authentication->authorityStrings() as $authority) {
            if (str_starts_with($authority, self::ROLE_PREFIX)) {
                $roles[] = substr($authority, strlen(self::ROLE_PREFIX));
            }
        }
        $context->set('roles', $roles);

        $tenant = $authentication->getAttributes()[$this->config->string('firefly.feature-flags.context.tenant-attribute', 'tenant')] ?? null;
        if (is_string($tenant) || is_int($tenant)) {
            $context->set('tenant', (string) $tenant);
        }
    }
}
