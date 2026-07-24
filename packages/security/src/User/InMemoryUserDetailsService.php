<?php

declare(strict_types=1);

namespace Firefly\Security\User;

use Firefly\Security\Authentication\Exception\UsernameNotFoundException;
use Firefly\Security\Core\SimpleGrantedAuthority;

/**
 * A config/array-backed UserDetailsService — the shipped default store. fromConfig() builds it from the
 * `firefly.security.users` map so a bare app can define users declaratively; passwords are the ENCODED strings
 * (typically `{id}`-prefixed for the DelegatingPasswordEncoder). Lookup is exact-match; an unknown user fails
 * loud with a 401 (never a null / silent skip).
 */
final class InMemoryUserDetailsService implements UserDetailsService
{
    /** @var array<string,UserDetails> */
    private array $users = [];

    /**
     * @param  list<UserDetails>  $users
     */
    public function __construct(array $users)
    {
        foreach ($users as $user) {
            $this->users[$user->getUsername()] = $user;
        }
    }

    /**
     * @param  array<string,array{password:string,authorities?:list<string>,enabled?:bool,locked?:bool}>  $users
     */
    public static function fromConfig(array $users): self
    {
        $records = [];
        foreach ($users as $username => $spec) {
            $authorities = array_map(
                static fn (string $a): SimpleGrantedAuthority => new SimpleGrantedAuthority($a),
                $spec['authorities'] ?? [],
            );
            $records[] = new User(
                $username,
                $spec['password'],
                $authorities,
                $spec['enabled'] ?? true,
                ! ($spec['locked'] ?? false),
            );
        }

        return new self($records);
    }

    public function loadUserByUsername(string $username): UserDetails
    {
        return $this->users[$username]
            ?? throw new UsernameNotFoundException("No user found for username [{$username}].");
    }
}
