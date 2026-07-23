<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Attributes;

use Attribute;

/**
 * Placed on a Firefly\Domain\DomainEvent SUBCLASS to route it to a specific integration-event destination. LaraFly
 * puts this on the EVENT (not the command handler as pyfly does) because the bridge trigger is an after-commit
 * listener where no handler is in scope — only a DomainEvent; the destination is an intrinsic property of the
 * integration event (design §4.2). HandlerScanner compiles it into a {eventClass => destination} map read by
 * EdaCommandEventPublisher; absent the attribute an event routes to firefly.cqrs.default_destination. Inert metadata.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class PublishDomainEvent
{
    public function __construct(public readonly ?string $destination = null) {}
}
