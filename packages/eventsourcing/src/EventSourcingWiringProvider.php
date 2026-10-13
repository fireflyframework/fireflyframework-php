<?php

declare(strict_types=1);

namespace Firefly\EventSourcing;

use Firefly\Context\Boot\FireflyServiceProvider;

/**
 * The boot half of firefly/eventsourcing, a plain FireflyServiceProvider because an AutoConfiguration records
 * candidacy only (the OrchestrationWiringProvider shape). Later tasks give it the EventSourcingManifest binding,
 * the firefly:eventsourcing:* commands and the migrations.
 */
final class EventSourcingWiringProvider extends FireflyServiceProvider
{
    public const string MIGRATIONS = __DIR__.'/../database/migrations';
}
