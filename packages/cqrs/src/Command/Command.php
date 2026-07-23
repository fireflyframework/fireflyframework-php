<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Command;

/**
 * Marker interface for a command — an intent to change the write model. A command is any object implementing this;
 * no base class or metadata is imposed, so a command is free to be a `final readonly` value object. Correlation is
 * carried by the bus/CorrelationContext + envelope headers, never forced onto the message (design §2.1).
 */
interface Command {}
