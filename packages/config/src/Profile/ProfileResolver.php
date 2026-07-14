<?php

declare(strict_types=1);

namespace Firefly\Config\Profile;

/**
 * Resolves the active profiles from the environment: FIREFLY_PROFILES_ACTIVE (comma-separated) takes
 * precedence; otherwise the single Laravel APP_ENV; otherwise the implicit "default" profile.
 */
final class ProfileResolver
{
    public function resolve(): Profiles
    {
        $explicit = getenv('FIREFLY_PROFILES_ACTIVE');
        if (is_string($explicit) && trim($explicit) !== '') {
            return new Profiles($this->split($explicit));
        }

        $env = getenv('APP_ENV');
        if (is_string($env) && trim($env) !== '') {
            return new Profiles([trim($env)]);
        }

        return new Profiles(['default']);
    }

    /**
     * @return list<string>
     */
    private function split(string $raw): array
    {
        $parts = array_map('trim', explode(',', $raw));

        return array_values(array_filter($parts, static fn (string $p): bool => $p !== ''));
    }
}
