<?php

declare(strict_types=1);

namespace Firefly\Security\Password;

/** bcrypt via PHP's native password_hash/password_verify (constant-time). */
final class BcryptPasswordEncoder implements PasswordEncoder
{
    /**
     * @param  array{cost?:int}  $options
     */
    public function __construct(private readonly array $options = ['cost' => 12]) {}

    public function encode(string $rawPassword): string
    {
        return password_hash($rawPassword, PASSWORD_BCRYPT, $this->options);
    }

    public function matches(string $rawPassword, string $encodedPassword): bool
    {
        return password_verify($rawPassword, $encodedPassword);
    }

    public function upgradeEncoding(string $encodedPassword): bool
    {
        return password_needs_rehash($encodedPassword, PASSWORD_BCRYPT, $this->options);
    }
}
