<?php

declare(strict_types=1);

use Firefly\Testing\Tests\Fixtures\Probe\ProbeComponent;
use Firefly\Testing\Tests\Support\ProbeFireflyTestCase;
use Illuminate\Foundation\Application;

// NOTE: the brief's literal test used `uses(new class extends FireflyTestCase { ... }::class)`.
// That instantiates the anonymous class immediately to read its ::class, which throws before Pest
// ever binds it — PHPUnit\Framework\TestCase::__construct() requires a `string $name` argument that
// a bare `new` expression never supplies (verified: ArgumentCountError). Every other *CapstoneTestCase
// in this monorepo is a named class passed as a class-string for the same reason (see
// packages/cqrs/tests/CapstoneCqrsIntegrationTest.php et al.) — following suit via ProbeFireflyTestCase.
uses(ProbeFireflyTestCase::class);

it('boots a real Firefly app with AutoConfigure first and resolves a scanned component', function () {
    /** @var ProbeFireflyTestCase $this */
    expect($this->app())->toBeInstanceOf(Application::class)
        ->and($this->fireflyContext()->has(ProbeComponent::class))->toBeTrue()
        ->and($this->fireflyContext()->get(ProbeComponent::class))->toBeInstanceOf(ProbeComponent::class);
});

it('seeds the errorlog log channel so the read-only sandbox never throws', function () {
    /** @var ProbeFireflyTestCase $this */
    expect($this->app()->make('config')->get('logging.default'))->toBe('errorlog');
});
