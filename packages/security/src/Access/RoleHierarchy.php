<?php

declare(strict_types=1);

namespace Firefly\Security\Access;

/**
 * A role-implication graph (`ROLE_ADMIN > ROLE_USER` means an admin also holds every user authority). BFS-expands
 * a granted set to every reachable authority so hasRole/hasAuthority need only a flat membership check. Pure and
 * reflection-free — built once from config and consulted process-wide.
 */
final class RoleHierarchy
{
    /**
     * @param  array<string,list<string>>  $hierarchy  role => directly-implied roles
     */
    public function __construct(private readonly array $hierarchy) {}

    /**
     * @param  list<string>  $rules  each `"ROLE_A > ROLE_B"`
     */
    public static function fromRules(array $rules): self
    {
        /** @var array<string,list<string>> $graph */
        $graph = [];
        foreach ($rules as $rule) {
            $parts = array_map('trim', explode('>', $rule, 2));
            if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
                continue;
            }
            $graph[$parts[0]][] = $parts[1];
        }

        return new self($graph);
    }

    /**
     * @param  list<string>  $authorities
     * @return list<string>
     */
    public function reachableAuthorities(array $authorities): array
    {
        $seen = [];
        $queue = $authorities;
        while ($queue !== []) {
            $current = array_shift($queue);
            if (isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            foreach ($this->hierarchy[$current] ?? [] as $implied) {
                if (! isset($seen[$implied])) {
                    $queue[] = $implied;
                }
            }
        }

        return array_keys($seen);
    }
}
