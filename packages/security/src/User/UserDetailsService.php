<?php

declare(strict_types=1);

namespace Firefly\Security\User;

use Firefly\Security\Authentication\Exception\UsernameNotFoundException;

/** Loads a UserDetails by username; MUST throw UsernameNotFoundException (401) when unknown — never return null. */
interface UserDetailsService
{
    /**
     * @throws UsernameNotFoundException
     */
    public function loadUserByUsername(string $username): UserDetails;
}
