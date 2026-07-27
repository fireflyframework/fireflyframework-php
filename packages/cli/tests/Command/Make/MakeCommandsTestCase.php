<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Command\Make;

use Firefly\Cli\CliServiceProvider;
use Firefly\Testing\FireflyTestCase;
use Illuminate\Support\ServiceProvider;

/**
 * Named Support test-case for MakeCommandsTest — Pest's `uses()` requires a class-string
 * (`function uses(string ...$classAndTraits)`), and an anonymous `new class extends FireflyTestCase {
 * ... }::class` expression instantiates the class immediately (to read its ::class), which throws
 * before Pest ever gets to bind it: the ultimate ancestor PHPUnit\Framework\TestCase::__construct()
 * requires a `string $name` argument that a bare `new` expression never supplies (verified: the same
 * fix is already applied in ClearCommandTestCase/IntrospectionCommandsTestCase for the identical
 * reason).
 *
 * CliServiceProvider IS required here — without it, the make:firefly-* commands are never registered
 * as Artisan commands and $this->artisan(...) throws CommandNotFoundException.
 *
 * Not `final`: Pest's uses() generates a per-test-file class that EXTENDS this one.
 */
class MakeCommandsTestCase extends FireflyTestCase
{
    /** @return list<class-string<ServiceProvider>> */
    protected function fireflyProviders(): array
    {
        return [CliServiceProvider::class];
    }
}
