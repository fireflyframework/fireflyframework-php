<?php

declare(strict_types=1);

namespace Firefly\Testing\Tests\Support;

use Firefly\Testing\FireflyTestCase;

/**
 * Named Support test-case for FireflyTestCaseTest — Pest's `uses()` requires a class-string
 * (`function uses(string ...$classAndTraits)`), and an anonymous `new class extends FireflyTestCase {
 * ... }::class` expression instantiates the class immediately (to read its ::class), which throws
 * before Pest ever gets to bind it: the ultimate ancestor PHPUnit\Framework\TestCase::__construct()
 * requires a `string $name` argument that a bare `new` expression never supplies. Every other
 * *CapstoneTestCase in this monorepo is a named class for the same reason — following suit here.
 * Not `final`: Pest's uses() generates a per-test-file class that EXTENDS this one.
 */
class ProbeFireflyTestCase extends FireflyTestCase
{
    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return ['firefly.scan.paths' => [
            'Firefly\\Testing\\Tests\\Fixtures\\Probe\\' => dirname(__DIR__).'/Fixtures/Probe',
        ]];
    }
}
