<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Tests\Fixtures\Mapper;

use Firefly\Container\Attributes\Bean;
use Firefly\Container\Attributes\Configuration;
use Firefly\Security\OAuth2\Client\User\GrantedAuthoritiesMapper;

/** How an application binds its mapper: a #[Bean] of the port type; the login provider's optional dependency picks it up. */
#[Configuration]
final class GroupsAuthoritiesConfiguration
{
    #[Bean]
    public function grantedAuthoritiesMapper(): GrantedAuthoritiesMapper
    {
        return new GroupsAuthoritiesMapper;
    }
}
