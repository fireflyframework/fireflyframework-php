<?php

declare(strict_types=1);

namespace Firefly\Security\Password;

/** Encodes and constant-time-verifies passwords (Spring's PasswordEncoder). */
interface PasswordEncoder
{
    public function encode(string $rawPassword): string;

    public function matches(string $rawPassword, string $encodedPassword): bool;

    /** True when the stored hash should be re-encoded with the current default (algorithm/cost drift). */
    public function upgradeEncoding(string $encodedPassword): bool;
}
