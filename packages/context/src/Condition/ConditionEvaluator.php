<?php

declare(strict_types=1);

namespace Firefly\Context\Condition;

use Firefly\Config\Config;
use Firefly\Config\Profile\Profiles;
use Firefly\Context\Condition\Attributes\ConditionalOnBean;
use Firefly\Context\Condition\Attributes\ConditionalOnClass;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingClass;
use Firefly\Context\Condition\Attributes\ConditionalOnProfile;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Context\Definition\BeanDefinition;
use Firefly\Context\Definition\BeanDefinitionRegistry;
use Firefly\Context\Definition\DefinitionSource;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * Evaluates #[ConditionalOn*] attributes in two phases, partitioned purely by `instanceof` (never
 * a match/switch on a type name) so the pipeline cannot forget a case when a new condition ships:
 *
 *  - Pass one: ConditionAttributes that are NOT BeanConditionAttributes (config, class presence,
 *    active profiles) — registry-independent.
 *  - Pass two: BeanConditionAttributes (does a bean of type X already exist in the registry?) —
 *    evaluated against the BeanDefinitionRegistry, never against resolved instances (matches
 *    Spring's @ConditionalOnBean, which queries the BeanDefinitionRegistry).
 */
final class ConditionEvaluator
{
    /** @var list<bool|int|string> falsy-ish scalar values for a valueless #[ConditionalOnProperty] */
    private const FALSY_VALUES = [false, 'false', '0', 0, ''];

    public function __construct(
        private readonly Config $config,
        private readonly Profiles $profiles,
    ) {}

    public function evaluate(ConditionAttribute $condition, ?BeanDefinitionRegistry $registry = null): ConditionOutcome
    {
        if ($condition instanceof ConditionalOnProperty) {
            return $this->evaluateOnProperty($condition);
        }

        if ($condition instanceof ConditionalOnClass) {
            return $this->evaluateOnClass($condition);
        }

        if ($condition instanceof ConditionalOnMissingClass) {
            return $this->evaluateOnMissingClass($condition);
        }

        if ($condition instanceof ConditionalOnProfile) {
            return $this->evaluateOnProfile($condition);
        }

        if ($condition instanceof BeanConditionAttribute) {
            if ($registry === null) {
                throw new ConfigurationException(
                    'Cannot evaluate ['.$condition::class.'] without a BeanDefinitionRegistry — this is a '.
                    'pipeline programming error (the caller forgot to pass the registry), not a user error.',
                );
            }

            return $this->evaluateBeanCondition($condition, $registry);
        }

        throw new ConfigurationException('Unknown condition attribute: ['.$condition::class.'].');
    }

    /**
     * A definition matches only if ALL of its conditions belonging to the CURRENT phase match
     * (AND semantics). A condition belonging to the OTHER phase is skipped — never re-evaluated.
     *
     * A BeanConditionAttribute on a User definition is a structural error regardless of which
     * phase is being run: user components are all registered in the same phase, so a bean
     * condition between two of them has no deterministic answer (it would depend on filesystem
     * scan order). Auto-configurations are fine because they run strictly after all user
     * definitions, so "does this user bean exist?" always has a well-defined answer.
     */
    public function matches(BeanDefinition $definition, bool $beanPhase, BeanDefinitionRegistry $registry): bool
    {
        foreach ($definition->conditions as $condition) {
            $isBeanCondition = $condition instanceof BeanConditionAttribute;

            if ($isBeanCondition && $definition->source === DefinitionSource::User) {
                throw new ConfigurationException(sprintf(
                    '#[%s] is not supported on user component [%s] — bean conditions are only valid on '.
                    'auto-configurations, which run after all user beans. Use #[ConditionalOnProperty] or '.
                    '#[ConditionalOnClass] instead.',
                    class_basename($condition),
                    $definition->class(),
                ));
            }

            if ($isBeanCondition !== $beanPhase) {
                continue;
            }

            if (! $this->evaluate($condition, $registry)->matched) {
                return false;
            }
        }

        return true;
    }

    private function evaluateOnProperty(ConditionalOnProperty $condition): ConditionOutcome
    {
        // A present-but-null value is treated as MISSING — exactly like M3's Config::required()
        // and ReflectionConfigBinder::resolveParameter() — because the common Laravel idiom
        // 'enabled' => env('FIREFLY_CACHE_ENABLED') yields null when the env var is unset.
        $value = $this->config->has($condition->name) ? $this->config->get($condition->name) : null;

        if ($value === null) {
            return $condition->matchIfMissing
                ? ConditionOutcome::match("@ConditionalOnProperty ({$condition->name}) is missing; matchIfMissing=true")
                : ConditionOutcome::noMatch("@ConditionalOnProperty ({$condition->name}) is missing; matchIfMissing=false");
        }

        $observed = $this->stringify($value);

        if ($condition->havingValue === null) {
            return $this->isFalsy($value)
                ? ConditionOutcome::noMatch("@ConditionalOnProperty ({$condition->name}={$observed}) is present but falsy")
                : ConditionOutcome::match("@ConditionalOnProperty ({$condition->name}={$observed}) is present and truthy");
        }

        return $observed === $condition->havingValue
            ? ConditionOutcome::match("@ConditionalOnProperty ({$condition->name}={$observed}) matched required value '{$condition->havingValue}'")
            : ConditionOutcome::noMatch("@ConditionalOnProperty ({$condition->name}={$observed}) did not match required value '{$condition->havingValue}'");
    }

    private function evaluateOnClass(ConditionalOnClass $condition): ConditionOutcome
    {
        $exists = class_exists($condition->class) || interface_exists($condition->class);

        return $exists
            ? ConditionOutcome::match("@ConditionalOnClass ({$condition->class}) is present on the classpath")
            : ConditionOutcome::noMatch("@ConditionalOnClass ({$condition->class}) is NOT present on the classpath");
    }

    private function evaluateOnMissingClass(ConditionalOnMissingClass $condition): ConditionOutcome
    {
        $exists = class_exists($condition->class) || interface_exists($condition->class);

        return $exists
            ? ConditionOutcome::noMatch("@ConditionalOnMissingClass ({$condition->class}) IS present on the classpath")
            : ConditionOutcome::match("@ConditionalOnMissingClass ({$condition->class}) is absent from the classpath");
    }

    private function evaluateOnProfile(ConditionalOnProfile $condition): ConditionOutcome
    {
        foreach ($condition->profiles as $profile) {
            if ($this->profiles->isActive($profile)) {
                return ConditionOutcome::match("@ConditionalOnProfile ({$profile}) is active");
            }
        }

        return ConditionOutcome::noMatch(sprintf(
            '@ConditionalOnProfile (%s) none active; active profiles: [%s]',
            implode(', ', $condition->profiles),
            implode(', ', $this->profiles->all()),
        ));
    }

    private function evaluateBeanCondition(BeanConditionAttribute $condition, BeanDefinitionRegistry $registry): ConditionOutcome
    {
        if ($condition instanceof ConditionalOnBean) {
            return $registry->containsType($condition->type)
                ? ConditionOutcome::match("@ConditionalOnBean ({$condition->type}) is defined in the registry")
                : ConditionOutcome::noMatch("@ConditionalOnBean ({$condition->type}) is NOT defined in the registry");
        }

        if ($condition instanceof ConditionalOnMissingBean) {
            return $registry->containsType($condition->type)
                ? ConditionOutcome::noMatch("@ConditionalOnMissingBean ({$condition->type}) IS defined in the registry")
                : ConditionOutcome::match("@ConditionalOnMissingBean ({$condition->type}) is not defined in the registry");
        }

        throw new ConfigurationException('Unknown bean condition attribute: ['.$condition::class.'].');
    }

    private function isFalsy(mixed $value): bool
    {
        return in_array($value, self::FALSY_VALUES, true);
    }

    private function stringify(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return get_debug_type($value);
    }
}
