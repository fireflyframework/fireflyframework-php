<?php

declare(strict_types=1);

namespace Firefly\Security\Authentication;

use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Security\Core\Authentication;

/**
 * First-supports-wins across the registered providers (Spring's ProviderManager). The FIRST provider whose
 * supports() returns true owns the attempt; its AuthenticationException propagates unchanged (already the right
 * 401 subtype). If no provider supports the token, that is itself a 401 (a request we cannot authenticate),
 * never a silent pass — fail-closed.
 */
final class ProviderManager implements AuthenticationManager
{
    /**
     * @param  list<AuthenticationProvider>  $providers
     */
    public function __construct(private readonly array $providers) {}

    public function authenticate(Authentication $authentication): Authentication
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($authentication)) {
                return $provider->authenticate($authentication);
            }
        }

        throw new AuthenticationException('No AuthenticationProvider supports the presented credentials.');
    }
}
