<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Registration;

/**
 * Where the registrations come from (Spring's ClientRegistrationRepository). The shipped implementation reads
 * `firefly.security.oauth2.client.registration.*` and resolves discovery on first use; an application may bind
 * its own — a database of tenants, say — and every filter and the manager go through it.
 *
 * registrationIds() is cheap and static (no discovery), which is what boot guards and the login page need;
 * findByRegistrationId() and all() hand back fully resolved registrations and may fetch a provider's discovery
 * document (cached) to do so.
 */
interface ClientRegistrationRepository
{
    public function findByRegistrationId(string $registrationId): ?ClientRegistration;

    /** @return list<string> */
    public function registrationIds(): array;

    /** @return list<ClientRegistration> */
    public function all(): array;
}
