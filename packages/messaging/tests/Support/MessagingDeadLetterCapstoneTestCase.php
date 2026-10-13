<?php

declare(strict_types=1);

namespace Firefly\Messaging\Tests\Support;

use Firefly\Messaging\DeadLetter\DeadLetterSchema;
use Firefly\Messaging\Listener\MessageListenerManifest;
use Firefly\Messaging\MessagingConsoleServiceProvider;
use Firefly\Messaging\MessagingServiceProvider;
use Firefly\Messaging\MessagingWiringProvider;
use Firefly\Messaging\Scanner\MessageListenerScanner;
use Firefly\Messaging\Tests\DeadLetterFixtures\FlakyLedgerListener;
use Firefly\Testing\FireflyDatabaseTestCase;
use Firefly\Testing\Fixture\ListenerSpy;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;

/**
 * The memory broker with firefly.messaging.dead-letter.store=database: a listener that exhausts its retries lands in
 * firefly_messaging_dead_letters through the REAL wiring (MessageListenerWiringPass wraps it with the bound store),
 * and the replay command — registered by package discovery — republishes it. Helpers are PUBLIC (PHPStan types a
 * test closure's `$this` as Pest's TestCall).
 */
abstract class MessagingDeadLetterCapstoneTestCase extends FireflyDatabaseTestCase
{
    /** @return list<class-string> */
    protected function fireflyProviders(): array
    {
        return [MessagingServiceProvider::class, MessagingWiringProvider::class, MessagingConsoleServiceProvider::class];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return ['firefly.messaging.provider' => 'memory', 'firefly.messaging.dead-letter.store' => 'database'];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        FlakyLedgerListener::$failing = true;
        $app->instance(MessageListenerManifest::class, new MessageListenerManifest(
            (new MessageListenerScanner)->scan(['Firefly\\Messaging\\Tests\\DeadLetterFixtures\\' => dirname(__DIR__).'/DeadLetterFixtures']),
        ));
        $app->singleton(ListenerSpy::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create(DeadLetterSchema::TABLE, fn (Blueprint $table) => DeadLetterSchema::blueprint($table));
    }

    /** @param array<string, mixed> $options */
    public function replay(array $options = []): int
    {
        /** @var Kernel $kernel */
        $kernel = $this->app()->make(Kernel::class);

        return $kernel->call('firefly:messaging:dead-letters:replay', $options);
    }

    public function replayOutput(): string
    {
        /** @var Kernel $kernel */
        $kernel = $this->app()->make(Kernel::class);

        return $kernel->output();
    }
}
