<?php

declare(strict_types=1);

namespace Firefly\Security\Password;

/** Argon2id via PHP's native password_hash/password_verify (constant-time). */
final class Argon2idPasswordEncoder implements PasswordEncoder
{
    public function encode(string $rawPassword): string
    {
        return password_hash($rawPassword, PASSWORD_ARGON2ID);
    }

    public function matches(string $rawPassword, string $encodedPassword): bool
    {
        return password_verify($rawPassword, $encodedPassword);
    }

    public function upgradeEncoding(string $encodedPassword): bool
    {
        return password_needs_rehash($encodedPassword, PASSWORD_ARGON2ID);
    }
}
