<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Registration;

/** The shipped repository: the configured registrations, resolved (and discovered) on first use through the mapper. */
final class PropertiesClientRegistrationRepository implements ClientRegistrationRepository
{
    public function __construct(private readonly OAuth2ClientPropertiesMapper $mapper) {}

    public function findByRegistrationId(string $registrationId): ?ClientRegistration
    {
        return $this->mapper->has($registrationId) ? $this->mapper->registration($registrationId) : null;
    }

    public function registrationIds(): array
    {
        return $this->mapper->registrationIds();
    }

    public function all(): array
    {
        return array_map(fn (string $id): ClientRegistration => $this->mapper->registration($id), $this->registrationIds());
    }
}
