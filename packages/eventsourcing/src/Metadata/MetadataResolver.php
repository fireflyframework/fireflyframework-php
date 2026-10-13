<?php

declare(strict_types=1);

namespace Firefly\EventSourcing\Metadata;

use Firefly\EventSourcing\Event\EventMetadata;

/** The contributors' metadata, with the caller's explicit metadata merged on top. */
final class MetadataResolver
{
    /** @var list<EventMetadataContributor> */
    private readonly array $contributors;

    /**
     * @param  iterable<EventMetadataContributor>  $contributors
     */
    public function __construct(iterable $contributors = [])
    {
        $list = [];
        foreach ($contributors as $contributor) {
            $list[] = $contributor;
        }
        $this->contributors = $list;
    }

    public function resolve(?EventMetadata $explicit = null): EventMetadata
    {
        $metadata = new EventMetadata;
        foreach ($this->contributors as $contributor) {
            $metadata = $contributor->contribute($metadata);
        }

        return $explicit === null ? $metadata : $metadata->merge($explicit);
    }
}
