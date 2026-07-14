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
}
