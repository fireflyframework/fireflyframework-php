<?php

declare(strict_types=1);

namespace Firefly\Eda\Database\Tests\Support;

use Firefly\Eda\Database\EdaDatabaseBootServiceProvider;
use Firefly\Eda\Database\Outbox\OutboxSchema;
use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * firefly:outbox:relay under provider=database, registered through EdaDatabaseBootServiceProvider exactly as package
 * discovery would. The helpers are PUBLIC for the reason OutboxRelayCommandTestCase documents (PHPStan types a test
 * closure's `$this` as Pest's TestCall). `--max-messages=0` relays one batch and stops.
 */
abstract class DatabaseRelayCommandTestCase extends FireflyDatabaseTestCase
{
    /** @return list<class-string> */
    protected function fireflyProviders(): array
    {
        return [EdaDatabaseBootServiceProvider::class];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return ['firefly.eda.provider' => 'database'];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create(OutboxSchema::TABLE, fn (Blueprint $table) => OutboxSchema::blueprint($table));
    }

    public function runRelay(): int
    {
        /** @var Kernel $kernel */
        $kernel = $this->app()->make(Kernel::class);

        return $kernel->call('firefly:outbox:relay', ['--max-messages' => 0]);
    }

    public function relayOutput(): string
    {
        /** @var Kernel $kernel */
        $kernel = $this->app()->make(Kernel::class);

        return $kernel->output();
    }
}
