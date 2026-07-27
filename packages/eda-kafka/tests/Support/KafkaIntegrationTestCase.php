<?php

declare(strict_types=1);

namespace Firefly\Eda\Kafka\Tests\Support;

use Firefly\Testing\Integration\RequiresDocker;
use PHPUnit\Framework\TestCase;

/**
 * The NAMED base test case KafkaRoundTripTest.php `uses()` instead of mixing the RequiresDocker trait in directly
 * via Pest's `uses(SomeTrait::class)` — the RabbitMqIntegrationTestCase precedent: that call mixes a trait into an
 * ANONYMOUS Pest-generated test class at runtime, which PHPStan/Larastan cannot see statically (`method.notFound`
 * on `$this->skipUnlessDocker()`) — the exact hazard FoundationFlowPostgresTestCase avoids by `use RequiresDocker;`
 * inside a real, named class PHPStan can inspect directly.
 *
 * skipUnlessDocker() itself runs from setUp() — RequiresDocker declares it `protected` (the
 * FoundationFlowPostgresTestCase/RabbitMqIntegrationTestCase precedent), so calling it from an `it()` closure
 * (lexically outside the class, even once PHPStan resolves `$this`'s type via a `@var` annotation) would be a
 * visibility violation. Running it in setUp() skips the whole test before its body executes.
 *
 * This is layered UNDER the file-level `->skip(!extension_loaded('rdkafka') || ...)` chain on each `it()`: that
 * chain (evaluated eagerly at collection time) is what keeps this suite from ever calling setUp() at all on a
 * no-ext machine, so Docker availability is only checked once ext-rdkafka + FIREFLY_KAFKA_BROKERS are BOTH present.
 */
abstract class KafkaIntegrationTestCase extends TestCase
{
    use RequiresDocker;

    protected function setUp(): void
    {
        $this->skipUnlessDocker();

        parent::setUp();
    }
}
