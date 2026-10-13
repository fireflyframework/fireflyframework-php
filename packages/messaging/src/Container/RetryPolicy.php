<?php

declare(strict_types=1);

namespace Firefly\Messaging\Container;

use Firefly\Config\Config;
use Firefly\Kernel\Exception\Framework\ConfigurationException;

/**
 * How many times a broker listener container attempts one delivery (the first included) and how long it waits in
 * between. The defaults are PyFly's listener container's — five attempts, 1s doubling, capped at 30s — because the
 * alternative on a real broker is worse: a listener that throws and is not retried is either redelivered in a hot
 * loop or lost. Read from firefly.messaging.listener.retry.*; a listener's own ListenerOptions override it. A
 * malformed duration or a multiplier below 1 refuses the boot, naming the key, rather than retrying on a guess.
 */
final readonly class RetryPolicy
{
    public function __construct(
        public int $maxAttempts = 5,
        public BackOff $backOff = new ExponentialBackOff,
    ) {
        if ($maxAttempts < 1) {
            throw new ConfigurationException("A listener retry policy attempts a delivery at least once; got max-attempts {$maxAttempts}.");
        }
    }

    public static function fromConfig(Config $config): self
    {
        $multiplier = $config->get('firefly.messaging.listener.retry.multiplier');
        if ($multiplier !== null && (! is_numeric($multiplier) || (float) $multiplier < 1.0)) {
            throw new ConfigurationException('firefly.messaging.listener.retry.multiplier must be a number of at least 1 (a back-off never shrinks); got '.var_export($multiplier, true).'.');
        }

        return new self(
            $config->int('firefly.messaging.listener.retry.max-attempts', 5),
            new ExponentialBackOff(
                Seconds::parse($config->get('firefly.messaging.listener.retry.initial-delay'), 1.0, 'firefly.messaging.listener.retry.initial-delay'),
                $multiplier === null ? 2.0 : (float) $multiplier,
                Seconds::parse($config->get('firefly.messaging.listener.retry.max-delay'), 30.0, 'firefly.messaging.listener.retry.max-delay'),
            ),
        );
    }

    public function withOptions(ListenerOptions $options): self
    {
        if ($options->maxAttempts === null && $options->backOff === null) {
            return $this;
        }

        return new self($options->maxAttempts ?? $this->maxAttempts, $options->backOff ?? $this->backOff);
    }
}
