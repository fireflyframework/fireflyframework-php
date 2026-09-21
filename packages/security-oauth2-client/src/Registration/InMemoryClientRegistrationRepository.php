<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Registration;

use Firefly\Kernel\Exception\Framework\ConfigurationException;

/** A fixed list of resolved registrations (Spring's InMemoryClientRegistrationRepository) — what a test or an application binding its own builds. */
final class InMemoryClientRegistrationRepository implements ClientRegistrationRepository
{
    /** @var array<string, ClientRegistration> */
    private array $registrations = [];

    /**
     * @param  list<ClientRegistration>  $registrations
     */
    public function __construct(array $registrations)
    {
        foreach ($registrations as $registration) {
            if (isset($this->registrations[$registration->registrationId])) {
                throw new ConfigurationException("The client registration [{$registration->registrationId}] is declared twice.");
            }
            $this->registrations[$registration->registrationId] = $registration;
        }
    }

    public function findByRegistrationId(string $registrationId): ?ClientRegistration
    {
        return $this->registrations[$registrationId] ?? null;
    }

    public function registrationIds(): array
    {
        return array_keys($this->registrations);
    }

    public function all(): array
    {
        return array_values($this->registrations);
    }
}
