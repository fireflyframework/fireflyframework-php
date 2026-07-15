<?php

declare(strict_types=1);

namespace Firefly\Context\Definition;

/**
 * Where a BeanDefinition came from.
 *
 * This distinction is why #[ConditionalOnBean]/#[ConditionalOnMissingBean] are legal on an
 * AutoConfiguration definition but illegal on a User one: auto-configurations are registered
 * strictly after all user definitions, so "does this bean exist yet?" always has a deterministic
 * answer for them. For two User definitions the answer would depend on filesystem scan order,
 * which is not deterministic — see ConditionEvaluator's user-component rule.
 */
enum DefinitionSource
{
    case User;
    case AutoConfiguration;
}
