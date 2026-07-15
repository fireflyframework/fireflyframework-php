<?php

declare(strict_types=1);

namespace Firefly\Context\Pass;

use Firefly\Context\Boot\BootContext;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\BootPhase;
use Firefly\Context\Definition\BeanDefinition;
use Firefly\Context\Definition\DefinitionSource;

/**
 * The seam where user #[Configuration]/#[Bean] definitions enter the registry.
 *
 * M4 has no context scanner yet (that lands in a later dispatch) — this pass accepts
 * already-scanned definitions via constructor injection and adds them to the registry; it does
 * not scan anything itself. An empty list (the default) correctly adds nothing: that is a
 * complete, honest implementation of this phase today, not a stand-in for one.
 *
 * Every definition added here is FORCED to DefinitionSource::User regardless of what the caller
 * supplied — this phase is exclusively the user seam, and later phases (ConditionPassTwoPass, the
 * user-component bean-condition guard) rely on that being trustworthy rather than merely assumed.
 */
final class UserConfigurationsPass implements BootPass
{
    /**
     * @param  list<BeanDefinition>  $definitions
     */
    public function __construct(
        private readonly array $definitions = [],
    ) {}

    public function phase(): BootPhase
    {
        return BootPhase::UserConfigurations;
    }

    public function order(): int
    {
        return 0;
    }

    public function run(BootContext $context): void
    {
        foreach ($this->definitions as $definition) {
            $context->definitions->add(new BeanDefinition(
                $definition->descriptor,
                $definition->conditions,
                DefinitionSource::User,
                $definition->beanConditions,
            ));
        }
    }
}
