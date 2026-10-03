<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Context;

use Firefly\Config\Profile\ProfileResolver;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Illuminate\Contracts\Config\Repository;

/**
 * The built-in process attributes: `application` (app.name, absent when blank) and `profiles` (the active Firefly
 * profiles, as ProfileResolver reads them: FIREFLY_PROFILES_ACTIVE, firefly.profiles.active, else app.env). Both
 * are fixed for the life of the process, so they are read once. They are the only ambient attributes the
 * management preview evaluates with (EvaluationContextResolver::process()).
 */
#[Component]
#[Order(-200)]
#[ConditionalOnProperty(name: 'firefly.feature-flags.enabled', havingValue: 'true')]
final class ApplicationEvaluationContextContributor implements EvaluationContextContributor
{
    private ?string $application = null;

    /** @var list<string>|null */
    private ?array $profiles = null;

    public function __construct(private readonly Repository $config) {}

    public function contribute(EvaluationContextBuilder $context): void
    {
        if ($this->profiles === null) {
            $name = $this->config->get('app.name');
            $this->application = is_string($name) && $name !== '' ? $name : null;
            $this->profiles = (new ProfileResolver($this->config))->resolve()->all();
        }

        if ($this->application !== null) {
            $context->set('application', $this->application);
        }
        $context->set('profiles', $this->profiles);
    }
}
