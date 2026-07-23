<?php

declare(strict_types=1);

namespace Firefly\Cqrs\Tests\ScannerFixtures;

use Firefly\Cqrs\Attributes\PublishDomainEvent;

#[PublishDomainEvent('not.an.event')]
final class NotAnEvent {}
