<?php

declare(strict_types=1);

use Firefly\Context\Boot\ApplicationContext;
use Firefly\EventSourcing\EventSourcingServiceProvider;
use Firefly\EventSourcing\EventSourcingWiringProvider;

/**
 * The two discovered providers boot over the real pipeline with nothing configured: installing the package is
 * inert until an application uses an event store.
 */
it('boots green with nothing configured', function (): void {
    $app = fireflyApplication([], [EventSourcingServiceProvider::class, EventSourcingWiringProvider::class]);

    expect($app->make(ApplicationContext::class))->toBeInstanceOf(ApplicationContext::class);
});
