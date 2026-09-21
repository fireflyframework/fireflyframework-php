<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Tests\Fixtures\Mapper;

use Firefly\Security\Core\GrantedAuthority;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Firefly\Security\OAuth2\Client\User\GrantedAuthoritiesMapper;
use Firefly\Security\OAuth2\Client\User\OAuth2UserAuthority;

/** The mapper an application writes: `groups: [engineering]` from the provider becomes `ROLE_ENGINEERING`, on top of what was granted. */
final class GroupsAuthoritiesMapper implements GrantedAuthoritiesMapper
{
    public function mapAuthorities(array $authorities): array
    {
        $mapped = $authorities;
        foreach ($authorities as $authority) {
            if (! $authority instanceof OAuth2UserAuthority) {
                continue;
            }
            $groups = $authority->getAttributes()['groups'] ?? [];
            foreach (is_array($groups) ? $groups : [] as $group) {
                if (is_string($group) && $group !== '') {
                    $mapped[] = new SimpleGrantedAuthority('ROLE_'.strtoupper($group));
                }
            }
        }

        /** @var list<GrantedAuthority> $mapped */
        return $mapped;
    }
}
