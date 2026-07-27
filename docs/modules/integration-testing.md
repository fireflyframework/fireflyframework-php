# Integration Testing

`firefly/testing` ships a small, opt-in seam for tests that need a **real backend** — a real Postgres, a real
Kafka broker — rather than an in-memory double. These tests are excluded from the default gate and never run
against a Docker-less CI unless explicitly requested.

## The `@group integration` gate

`phpunit.xml.dist` excludes the `integration` group from the default test run:

```xml
<groups>
    <exclude>
        <group>integration</group>
    </exclude>
</groups>
```

So `vendor/bin/pest` (the whole-branch gate, `composer test`) never touches an integration test. Tag any
Docker-backed test with Pest's `@group` annotation (a PHPDoc-style docblock above the test, exactly as PHPUnit
reads it):

```php
<?php

/** @group integration */
it('reads from a real Postgres container', function () {
    // ...
});
```

## `RequiresDocker` + `skipUnlessDocker()`

`Firefly\Testing\Integration\RequiresDocker` is a trait (`@mixin PHPUnit\Framework\TestCase`) that marks a test
skipped — rather than erroring — when Docker isn't available, so a Docker-less environment that does run the
`integration` group (e.g. by explicit request) degrades to a skip instead of a hard failure:

```php
trait RequiresDocker
{
    protected function skipUnlessDocker(): void { /* ... */ }
}
```

Call `skipUnlessDocker()` at the top of `setUp()` (or the top of the test) in any `FireflyTestCase` subclass that
needs Docker:

```php
<?php

declare(strict_types=1);

use Firefly\Testing\FireflyDatabaseTestCase;
use Firefly\Testing\Integration\RequiresDocker;

/** @group integration */
final class PostgresRepositoryIntegrationTest extends FireflyDatabaseTestCase
{
    use RequiresDocker;

    protected function setUp(): void
    {
        $this->skipUnlessDocker();
        parent::setUp();
    }
}
```

The underlying probe, `is_docker_available(): bool` (`Firefly\Testing\functions.php`), runs `docker info` and
returns `true` iff it exits `0` — it also honors `DOCKER_HOST` when set (the `docker` CLI does), and it never
throws: any failure to run `docker` at all yields `false`, so calling it with no Docker installed is always safe.

## `fireflyConfigFor()` — mapping a testcontainer into Firefly config

`fireflyConfigFor(object $container, string $prefix = 'database.connections.testing'): array` maps a started
testcontainer into a flat, dot-keyed Firefly/Laravel config array — the `@ServiceConnection` analogue. It is
duck-typed: it reads whichever of `getHost()`/`getMappedPort()`/`getUsername()`/`getPassword()`/`getDatabase()`
the container object actually exposes, so it works with any testcontainers-php container class without a hard
dependency on the library's types:

```php
function fireflyConfigFor(object $container, string $prefix = 'database.connections.testing'): array
```

```php
<?php

$config = fireflyConfigFor($postgresContainer);
// [
//     'database.connections.testing.host' => '127.0.0.1',
//     'database.connections.testing.port' => 55432,
//     'database.connections.testing.username' => 'postgres',
//     'database.connections.testing.password' => 'secret',
//     'database.connections.testing.database' => 'app',
// ]
```

Feed the result straight into a `FireflyTestCase` subclass's `configOverrides()`, or set each key on the config
repository from `defineFireflyEnvironment()`.

## A representative Postgres testcontainers example

`testcontainers/testcontainers` is **not** a hard dependency of `firefly/testing` — it is listed only under
`suggest` (`composer.json`'s `suggest` block: `"testcontainers/testcontainers": "Ephemeral Docker backends for
@group integration tests (fireflyConfigFor())."`). Require it yourself, in your own package's `require-dev`, to
write tests like this:

```php
<?php

declare(strict_types=1);

use Firefly\Testing\FireflyDatabaseTestCase;
use Firefly\Testing\Integration\RequiresDocker;
use Illuminate\Foundation\Application;
use Testcontainers\Container\PostgresContainer;

/** @group integration */
final class PostgresRepositoryIntegrationTest extends FireflyDatabaseTestCase
{
    use RequiresDocker;

    private ?PostgresContainer $postgres = null;

    protected function setUp(): void
    {
        $this->skipUnlessDocker();
        $this->postgres = (new PostgresContainer)->withPostgresVersion('16')->start();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->postgres?->stop();
        parent::tearDown();
    }

    protected function defineFireflyEnvironment(Application $app): void
    {
        $config = $app->make('config');
        foreach (fireflyConfigFor($this->postgres, 'database.connections.pgsql') as $key => $value) {
            $config->set($key, $value);
        }
        $config->set('database.connections.pgsql.driver', 'pgsql');
        $config->set('database.default', 'pgsql');
    }

    public function test_it_reads_and_writes_against_a_real_postgres(): void
    {
        // ... exercise a real repository against the started container ...
    }
}
```

> The container start/stop dance runs only for this one test class, gated by `skipUnlessDocker()`; nothing about
> the default `FireflyDatabaseTestCase` sqlite `:memory:` seed changes for every other test in the suite.

## Running the integration suite

The default gate skips `@group integration` entirely. Run it explicitly, on a machine/CI job with Docker
available:

```bash
vendor/bin/pest --group=integration
```

Docker-less environments that invoke `vendor/bin/pest --group=integration` still get a clean run: every test
using `RequiresDocker::skipUnlessDocker()` reports **skipped**, not failed.
