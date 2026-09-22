<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Authorization;

/**
 * What a principal has already agreed a client may do (Spring's OAuth2AuthorizationConsent): the scopes, per
 * client and principal. A later request whose scopes are all covered skips the consent page; one that asks for
 * more shows it again, and the approval MERGES (withScopes) rather than replaces, so a user never loses a scope
 * they already granted by approving a narrower request. The id joins client and principal with `|`, which
 * neither a config-map key nor a username of the shipped user stores may contain, and is what the Eloquent
 * driver keys its row by.
 */
final readonly class OAuth2AuthorizationConsent
{
    /**
     * @param  list<string>  $scopes
     */
    public function __construct(
        public string $registeredClientId,
        public string $principalName,
        public array $scopes,
    ) {}

    public static function id(string $registeredClientId, string $principalName): string
    {
        return $registeredClientId.'|'.$principalName;
    }

    /**
     * @param  list<string>  $scopes
     */
    public function withScopes(array $scopes): self
    {
        return new self($this->registeredClientId, $this->principalName, array_values(array_unique([...$this->scopes, ...$scopes])));
    }

    /**
     * @param  list<string>  $scopes
     */
    public function hasScopes(array $scopes): bool
    {
        foreach ($scopes as $scope) {
            if (! in_array($scope, $this->scopes, true)) {
                return false;
            }
        }

        return true;
    }
}
