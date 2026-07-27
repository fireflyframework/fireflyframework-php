<?php

declare(strict_types=1);

namespace Firefly\Testing\Tests\Support;

use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;

/**
 * Named Support test-case for FireflyDatabaseTestCaseTest — same anonymous-class-::class gotcha
 * documented on ProbeFireflyTestCase applies here: Pest's `uses()` requires a class-string, and
 * `new class extends FireflyDatabaseTestCase { ... }::class` instantiates the anonymous class
 * immediately (to read its ::class) before Pest ever gets to bind it — PHPUnit\Framework\TestCase's
 * constructor requires a `string $name` a bare `new` expression never supplies. Following every other
 * *CapstoneTestCase in this monorepo (named class passed as a class-string) instead.
 *
 * Also doubles as the compose-not-clobber proof: this subclass's own configOverrides() entry
 * (`firefly.widgets.enabled`) must survive alongside FireflyDatabaseTestCase's sqlite seed — the trait
 * sets `database.*` directly in resolveApplicationConfiguration() AFTER parent::configOverrides() are
 * applied, so it never touches unrelated keys.
 */
class WidgetFireflyDatabaseTestCase extends FireflyDatabaseTestCase
{
    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return ['firefly.widgets.enabled' => true];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        $this->createSchema('widgets', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
        });
    }
}
