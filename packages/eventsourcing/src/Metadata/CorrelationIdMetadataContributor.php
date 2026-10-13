<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Metadata;

use Closure;
use Firefly\EventSourcing\Event\EventMetadata;
use Illuminate\Log\Context\Repository;

/**
 * The request's correlation id, read from Laravel's Context under `firefly.correlation_id`. firefly/web's
 * CorrelationIdFilter writes it there, so an event appended while serving a request carries the id of that request,
 * the same `x-correlation-id` the response, the logs and the eda envelopes carry.
 *
 * Context is SCOPED (one per request, flushed under Octane) while this contributor lives in a singleton, so the
 * auto-configuration hands it a closure that resolves the current Context on every append. A test may pass the
 * Repository itself.
 */
final class CorrelationIdMetadataContributor implements EventMetadataContributor
{
    public const string KEY = 'firefly.correlation_id';

    /**
     * @param  Repository|(Closure(): ?Repository)|null  $context
     */
    public function __construct(private readonly Repository|Closure|null $context = null) {}

    public function contribute(EventMetadata $metadata): EventMetadata
    {
        $context = $this->context instanceof Closure ? ($this->context)() : $this->context;
        if ($metadata->correlationId !== null || $context === null) {
            return $metadata;
        }
        $id = $context->get(self::KEY);

        return is_string($id) && $id !== '' ? $metadata->withCorrelationId($id) : $metadata;
    }
}
