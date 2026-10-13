<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Settings;

/** firefly.orchestration.events.* and event-gateway.enabled — lifecycle events out, trigger events in. */
final readonly class EventsSettings
{
    public function __construct(
        public bool $enabled,
        public string $defaultTopic,
        public bool $gatewayEnabled,
    ) {}
}
