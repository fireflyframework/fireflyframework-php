<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Metadata;

use Firefly\EventSourcing\Event\EventMetadata;
use Illuminate\Contracts\Auth\Factory;
use Throwable;

/**
 * The authenticated user of the default Laravel guard, as `x-user-id` (firefly.eventsourcing.metadata.capture-user).
 * It never authenticates anybody: a guard with no user, or no guard at all, adds nothing.
 */
final class AuthenticatedUserMetadataContributor implements EventMetadataContributor
{
    public function __construct(private readonly Factory $auth) {}

    public function contribute(EventMetadata $metadata): EventMetadata
    {
        if ($metadata->userId !== null) {
            return $metadata;
        }

        try {
            $guard = $this->auth->guard();
            $id = $guard->hasUser() ? $guard->id() : null;
        } catch (Throwable) {
            return $metadata;
        }

        return is_int($id) || (is_string($id) && $id !== '') ? $metadata->withUserId((string) $id) : $metadata;
    }
}
