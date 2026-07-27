<?php

declare(strict_types=1);

namespace Firefly\Eda\Rabbitmq\Tests\Support;

use Firefly\Testing\Integration\RequiresDocker;
use PHPUnit\Framework\TestCase;

/**
 * The NAMED base test case RabbitMqRoundTripTest.php `uses()` instead of mixing the RequiresDocker trait in
 * directly via Pest's `uses(SomeTrait::class)`: that call mixes a trait into an ANONYMOUS Pest-generated test
 * class at runtime, which PHPStan/Larastan cannot see statically (`method.notFound` on
 * `$this->skipUnlessDocker()`) — the exact hazard FoundationFlowPostgresTestCase avoids by `use RequiresDocker;`
 * inside a real, named class PHPStan can inspect directly.
 *
 * skipUnlessDocker() itself runs from setUp() — RequiresDocker declares it `protected` (the
 * FoundationFlowPostgresTestCase precedent), so calling it from an `it()` closure (lexically outside the class,
 * even once PHPStan resolves `$this`'s type via a `@var` annotation) would be a visibility violation. Running it
 * in setUp() skips the whole test before its body executes, exactly like the Postgres precedent.
 */
abstract class RabbitMqIntegrationTestCase extends TestCase
{
    use RequiresDocker;

    protected function setUp(): void
    {
        $this->skipUnlessDocker();

        parent::setUp();
    }
}
