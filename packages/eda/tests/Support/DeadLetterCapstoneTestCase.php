<?php

declare(strict_types=1);

namespace Firefly\Eda\Tests\Support;

use Firefly\Eda\DeadLetter\DeadLetterSchema;
use Firefly\Eda\EdaConsumerServiceProvider;
use Firefly\Eda\EdaServiceProvider;
use Firefly\Eda\EdaWiringProvider;
use Firefly\Eda\Listener\EventListenerManifest;
use Firefly\Eda\Scanner\EventListenerScanner;
use Firefly\Eda\Tests\DeadLetterFixtures\FlakyOrderListener;
use Firefly\Testing\FireflyDatabaseTestCase;
use Firefly\Testing\Fixture\ListenerSpy;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;

/**
 * The memory bus with firefly.eda.dead-letter.store=database: a listener that exhausts its retries lands in
 * firefly_eda_dead_letters through the REAL wiring (EventListenerWiringPass wraps it with the bound store), and the
 * replay command — registered by package discovery — republishes it. Helpers are PUBLIC (PHPStan types a test
 * closure's `$this` as Pest's TestCall).
 */
abstract class DeadLetterCapstoneTestCase extends FireflyDatabaseTestCase
{
    /** @return list<class-string> */
    protected function fireflyProviders(): array
    {
        return [EdaServiceProvider::class, EdaWiringProvider::class, EdaConsumerServiceProvider::class];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return ['firefly.eda.provider' => 'memory', 'firefly.eda.dead-letter.store' => 'database'];
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        FlakyOrderListener::$failing = true;
        $app->instance(EventListenerManifest::class, new EventListenerManifest(
            (new EventListenerScanner)->scan(['Firefly\\Eda\\Tests\\DeadLetterFixtures\\' => dirname(__DIR__).'/DeadLetterFixtures']),
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

        return $kernel->call('firefly:eda:dead-letters:replay', $options);
    }

    public function replayOutput(): string
    {
        /** @var Kernel $kernel */
        $kernel = $this->app()->make(Kernel::class);

        return $kernel->output();
    }
}
