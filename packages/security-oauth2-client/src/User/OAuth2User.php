<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\User;

use Firefly\Security\Core\GrantedAuthority;

/**
 * A principal signed in through an OAuth2 provider (Spring's OAuth2User): named by the configured attribute,
 * carrying every attribute the provider answered, and the authorities the user service granted BEFORE the
 * GrantedAuthoritiesMapper ran (the mapped ones are on the Authentication).
 */
interface OAuth2User
{
    public function getName(): string;

    /** @return array<string, mixed> */
    public function getAttributes(): array;

    public function getAttribute(string $name): mixed;

    /** @return list<GrantedAuthority> */
    public function getAuthorities(): array;
}
