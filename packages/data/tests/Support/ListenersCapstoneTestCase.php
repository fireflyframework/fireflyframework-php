<?php

declare(strict_types=1);

namespace Firefly\Data\Tests\Support;

use Firefly\Data\DataServiceProvider;
use Firefly\Data\DataWiringProvider;
use Firefly\Data\Proxy\ProxyClassGenerator;
use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Tests\Fixtures\Listeners\ListenersTransactionalConfiguration;
use Firefly\Data\Tests\Fixtures\Listeners\NoteAudit;
use Firefly\Data\Transaction\TransactionalManifestCompiler;
use Firefly\Testing\FireflyDatabaseTestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Schema;

/**
 * Boots the REAL kernel with BOTH data providers — DataServiceProvider (the beans) and DataWiringProvider (the
 * listener pass) — plus the Listeners fixture provider over sqlite. Proxies and the manifest (now carrying the
 * `listeners` map) are compiled INLINE exactly as firefly:cache would; the REAL DispatcherEventPublisher is
 * left in place, because the point is that a publish through the port reaches a listener the wiring pass
 * registered on Illuminate's dispatcher.
 */
abstract class ListenersCapstoneTestCase extends FireflyDatabaseTestCase
{
    /** @return list<class-string> */
    protected function fireflyProviders(): array
    {
        return [
            DataServiceProvider::class,
            DataWiringProvider::class,
            ListenersComponentsProvider::class,
        ];
    }

    /** @return array<string, mixed> */
    protected function configOverrides(): array
    {
        return ['firefly.data.transactional-event-listeners.enabled' => $this->listenersEnabled()];
    }

    /** The key under test; ListenersDisabledCapstoneTestCase flips it (a boot-time read, so a separate boot). */
    protected function listenersEnabled(): bool
    {
        return true;
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        $psr4 = ['Firefly\\Data\\Tests\\Fixtures\\Listeners\\' => dirname(__DIR__).'/Fixtures/Listeners'];
        $scanner = new TransactionalScanner;
        $generator = new ProxyClassGenerator;

        foreach ($scanner->scanProxyMethods($psr4) as $target => $methods) {
            $generator->load($target, $methods);
        }

        (new TransactionalManifestCompiler)->write($scanner->scan($psr4), ListenersTransactionalConfiguration::manifestPath());
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('notes', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
        });

        NoteAudit::reset();
    }
}
