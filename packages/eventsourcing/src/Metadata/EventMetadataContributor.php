<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Metadata;

use Firefly\EventSourcing\Event\EventMetadata;

/**
 * Adds what the current request knows to every appended event's metadata. The framework ships the correlation id
 * and the authenticated Laravel user. A #[Component] implementing this adds anything else, such as a
 * firefly/security principal or a tenant. Contributors run in #[Order]; metadata a save() is given explicitly wins
 * over all of them.
 */
interface EventMetadataContributor
{
    public function contribute(EventMetadata $metadata): EventMetadata;
}
