<?php

declare(strict_types=1);

namespace Firefly\Eda\Kafka;

use RdKafka\Conf;
use RdKafka\Producer;
use RuntimeException;

/**
 * Builds an rdkafka Producer — but ONLY when the ext is present. The skip-if-missing seam (ServeCommand
 * precedent). producer()'s real RdKafka\Producer return type is declared directly (resolved by PHPStan via the
 * kwn/php-rdkafka-stubs dev-dep — see root composer.json) rather than a weakened `object` type.
 *
 * The `use RdKafka\...;` imports above are added by this repo's own Pint preset (`fully_qualified_strict_types`,
 * part of the shared root `laravel` preset — not overridable per-package without editing the root config, which
 * is out of scope for this package) — NOT hand-written. This is deliberately SAFE even with ext-rdkafka absent:
 * a PHP `use` statement is a compile-time alias only and never triggers class resolution by itself (verified: a
 * file with `use RdKafka\Producer;` autoloads with zero fatal on this exact no-ext machine — see
 * ReflectionFreeEdaKafkaTest, which requires every file in this directory to prove it). The type is only ever
 * actually RESOLVED at the moment a value is returned/assigned against it, and available() above ALWAYS throws
 * BEFORE that point when the extension is missing — so this declaration is never evaluated against a real value
 * on a no-ext machine. The genuinely load-bearing guard is extension_loaded('rdkafka') as the FIRST statement of
 * producer(), not the absence of a `use` statement.
 */
final class KafkaProducerFactory
{
    public function __construct(private readonly string $brokers = '127.0.0.1:9092') {}

    public static function available(): bool
    {
        return extension_loaded('rdkafka');
    }

    public function producer(): Producer
    {
        if (! self::available()) {
            throw new RuntimeException('The Kafka adapter requires ext-rdkafka (firefly.eda.provider=kafka). Install librdkafka + the rdkafka extension, or choose another provider.');
        }

        $conf = new Conf;
        $conf->set('metadata.broker.list', $this->brokers);

        return new Producer($conf);
    }
}
