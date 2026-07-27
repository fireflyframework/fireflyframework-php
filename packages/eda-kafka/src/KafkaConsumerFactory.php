<?php

declare(strict_types=1);

namespace Firefly\Eda\Kafka;

use RdKafka\Conf;
use RdKafka\KafkaConsumer;
use RuntimeException;

/**
 * Builds an rdkafka KafkaConsumer — but ONLY when the ext is present, mirroring KafkaProducerFactory's
 * skip-if-missing seam exactly. consumer()'s real RdKafka\KafkaConsumer return type is declared directly (resolved
 * by PHPStan via the kwn/php-rdkafka-stubs dev-dep — see root composer.json) rather than a weakened `object` type.
 *
 * The `use RdKafka\...;` imports above are added by this repo's own Pint preset (`fully_qualified_strict_types`,
 * part of the shared root `laravel` preset) — NOT hand-written, and load-safe for the exact reasons documented on
 * KafkaProducerFactory: a `use` statement is a compile-time alias only and is never itself resolved by the engine.
 * available() is the genuinely load-bearing guard, called as the FIRST statement of consumer().
 */
final class KafkaConsumerFactory
{
    public function __construct(
        private readonly string $brokers = '127.0.0.1:9092',
        private readonly string $groupId = 'firefly',
    ) {}

    public static function available(): bool
    {
        return extension_loaded('rdkafka');
    }

    public function consumer(): KafkaConsumer
    {
        if (! self::available()) {
            throw new RuntimeException('The Kafka adapter requires ext-rdkafka (firefly.eda.provider=kafka). Install librdkafka + the rdkafka extension, or choose another provider.');
        }

        $conf = new Conf;
        $conf->set('group.id', $this->groupId);
        $conf->set('metadata.broker.list', $this->brokers);
        $conf->set('auto.offset.reset', 'earliest');

        return new KafkaConsumer($conf);
    }
}
