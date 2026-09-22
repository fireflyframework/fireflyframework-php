<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\User;

use Firefly\Security\Core\GrantedAuthority;
use InvalidArgumentException;

/**
 * The plain-OAuth2 principal (Spring's DefaultOAuth2User): the userinfo attributes, named by
 * `user_name_attribute` (`id` for GitHub — an integer there, so the name is its string form). Refuses to exist
 * without that attribute, exactly as Spring's constructor asserts it.
 */
final readonly class DefaultOAuth2User implements OAuth2User
{
    /**
     * @param  list<GrantedAuthority>  $authorities
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        private array $authorities,
        private array $attributes,
        private string $nameAttributeKey,
    ) {
        if (! is_scalar($attributes[$nameAttributeKey] ?? null)) {
            throw new InvalidArgumentException("The attributes carry no [{$nameAttributeKey}] to name the principal by.");
        }
    }

    public function getName(): string
    {
        $name = $this->attributes[$this->nameAttributeKey] ?? null;

        return is_scalar($name) ? (string) $name : '';
    }

    public function getAttributes(): array
    {
        return $this->attributes;
    }

    public function getAttribute(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function getAuthorities(): array
    {
        return $this->authorities;
    }
}
