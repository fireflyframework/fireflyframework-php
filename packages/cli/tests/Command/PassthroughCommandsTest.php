<?php

declare(strict_types=1);

use Firefly\Cli\Tests\Command\PassthroughCommandsTestCase;
use Firefly\Cli\Tests\Support\ArtisanAssertions;
use Laravel\Octane\Octane;

// NOTE: the brief's literal test used `uses(new class extends FireflyTestCase { ... }::class)`.
// That instantiates the anonymous class immediately to read its ::class, which throws before Pest
// ever binds it — PHPUnit\Framework\TestCase::__construct() requires a `string $name` argument that
// a bare `new` expression never supplies (verified: ArgumentCountError). Following the monorepo
// convention (see ClearCommandTestCase) with a NAMED support class, which also registers
// CliServiceProvider so firefly:db / firefly:serve are actually bound.
uses(PassthroughCommandsTestCase::class);

it('firefly:db delegates to the migrate command', function () {
    /** @var PassthroughCommandsTestCase $this */
    // sqlite :memory: default via the harness; a no-migration run still exits 0.
    ArtisanAssertions::exitCode($this->artisan('firefly:db', ['action' => 'migrate']), 0);
});

it('firefly:serve resolves without booting a real server under --help', function () {
    /** @var PassthroughCommandsTestCase $this */
    ArtisanAssertions::exitCode($this->artisan('firefly:serve', ['--help' => true]), 0);
});

it('the octane probe class exists in this monorepo, so firefly:serve selects octane:start', function () {
    // Asserts the delegation TARGET the octane class_exists() probe would select, without starting
    // a real server (octane:start / serve are both long-running processes — out of scope per spec §7).
    expect(class_exists(Octane::class))->toBeTrue();
});
