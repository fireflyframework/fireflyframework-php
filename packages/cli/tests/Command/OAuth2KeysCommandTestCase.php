<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Command;

use Firefly\Cli\CliServiceProvider;
use Firefly\Testing\FireflyTestCase;
use Illuminate\Support\ServiceProvider;

/**
 * Named support test-case for OAuth2KeysCommandTest — Pest's uses() takes a class-string, and an anonymous
 * `new class extends FireflyTestCase {}::class` instantiates before Pest binds it (see ClearCommandTestCase).
 * Not `final`: Pest's uses() generates a per-file class that extends it.
 */
class OAuth2KeysCommandTestCase extends FireflyTestCase
{
    /** @return list<class-string<ServiceProvider>> */
    protected function fireflyProviders(): array
    {
        return [CliServiceProvider::class];
    }

    /** A per-process scratch directory for the PEM files the command writes; each test names its own file. */
    public function outDir(): string
    {
        $dir = sys_get_temp_dir().'/firefly-oauth2-keys-'.getmypid();
        if (! is_dir($dir)) {
            mkdir($dir, 0o700, true);
        }

        return $dir;
    }
}
