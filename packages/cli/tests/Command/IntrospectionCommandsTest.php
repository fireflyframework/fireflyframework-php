<?php

declare(strict_types=1);

use Firefly\Cli\Tests\Command\IntrospectionCommandsTestCase;
use Firefly\Cli\Tests\Support\ArtisanAssertions;
use Firefly\Kernel\Version;

// NOTE: the brief's literal test used `uses(new class extends FireflyTestCase { ... }::class)`.
// That instantiates the anonymous class immediately to read its ::class, which throws before Pest
// ever binds it (verified: ArgumentCountError — see IntrospectionCommandsTestCase's docblock).
// Following the monorepo's NAMED support-class convention instead.
uses(IntrospectionCommandsTestCase::class);

it('firefly:health renders the health status', function () {
    /** @var IntrospectionCommandsTestCase $this */
    ArtisanAssertions::outputContains($this->artisan('firefly:health'), 0, 'status');
});

it('firefly:about renders the framework version', function () {
    /** @var IntrospectionCommandsTestCase $this */
    ArtisanAssertions::outputContains($this->artisan('firefly:about'), 0, Version::VERSION);
});

it('firefly:routes renders the mappings endpoint', function () {
    /** @var IntrospectionCommandsTestCase $this */
    ArtisanAssertions::exitCode($this->artisan('firefly:routes'), 0);
});

it('firefly:metrics degrades gracefully when metrics are disabled', function () {
    /** @var IntrospectionCommandsTestCase $this */
    // No ObservabilityServiceProvider is wired in IntrospectionCommandsTestCase::fireflyProviders(), so
    // the observability MetricsEndpoint #[Component] never enters ActuatorRouteRegistrar's definitions
    // loop and 'metrics' is absent from the ActuatorRegistry. ActuatorCliRenderer::render() sees
    // get('metrics') === null and takes its graceful-degrade branch: warn() + exit 0, not a failure.
    ArtisanAssertions::exitCode($this->artisan('firefly:metrics'), 0);
});
