<?php

declare(strict_types=1);

namespace Firefly\Security\Password;

/**
 * INSECURE plaintext encoder — for tests / local `{noop}` credentials ONLY. Never make it the delegating
 * default in production. Comparison is still constant-time (hash_equals) to avoid a timing oracle even here.
 */
final class NoOpPasswordEncoder implements PasswordEncoder
{
    public function encode(string $rawPassword): string
    {
        return $rawPassword;
    }

    public function matches(string $rawPassword, string $encodedPassword): bool
    {
        return hash_equals($encodedPassword, $rawPassword);
    }

    public function upgradeEncoding(string $encodedPassword): bool
    {
        return false;
    }
}
