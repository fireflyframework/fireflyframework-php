<?php

declare(strict_types=1);

namespace Firefly\Config\Profile;

/**
 * The set of active configuration profiles (Spring-style). Analogous to spring.profiles.active.
 */
final readonly class Profiles
{
    /**
     * @param  list<string>  $active
     */
    public function __construct(public array $active) {}

    public function isActive(string $profile): bool
    {
        return in_array($profile, $this->active, true);
    }

    /**
     * @return list<string>
     */
    public function all(): array
    {
        return $this->active;
    }

    public function isEmpty(): bool
    {
        return $this->active === [];
    }

    /**
     * THE profile predicate: may a bean that declares $required exist under these active profiles?
     *
     * Empty $required means the bean declared no requirement at all and is therefore always
     * accepted — an unannotated component must never be gated. A non-empty $required is an OR, not
     * an AND: #[Profile('dev', 'test')] reads as "either of these", matching Spring's @Profile and,
     * deliberately, firefly/context's #[ConditionalOnProfile] evaluation exactly. That parity is
     * the whole point of putting the rule here rather than re-deriving it at each gate — the
     * framework previously had one copy of the semantics living inside ConditionEvaluator and a
     * #[Profile] attribute that no code consumed at all, which is precisely how two spellings of
     * the same idea drift apart.
     *
     * Note what is NOT supported, on purpose: Spring's negated form (@Profile("!prod")) and its
     * &/| expression grammar. Adding negation here alone would immediately make #[Profile('!prod')]
     * behave differently from #[ConditionalOnProfile('!prod')], so if it is ever wanted it has to
     * land in both places in the same change.
     *
     * @param  list<string>  $required  the profiles a bean declares, or [] when it declares none
     */
    public function accepts(array $required): bool
    {
        if ($required === []) {
            return true;
        }

        foreach ($required as $profile) {
            if ($this->isActive($profile)) {
                return true;
            }
        }

        return false;
    }
}
