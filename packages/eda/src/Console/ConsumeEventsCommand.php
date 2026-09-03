<?php

declare(strict_types=1);

namespace Firefly\Eda\Console;

use Firefly\Config\Config;
use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\Consumer\ConsumerLoop;
use Firefly\Eda\Consumer\ConsumerOptions;
use Firefly\Eda\Consumer\EventConsumer;
use Firefly\Eda\Consumer\TopicSubscriptionResolver;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\Listener\EventListenerManifest;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Console\Command;

/**
 * Drives the active broker's EventConsumer until a bound trips or a signal arrives. Resolves the EventConsumer +
 * SubscriberRegistry a broker package bound (the same registry EventListenerWiringPass populated this process's boot),
 * derives the concrete broker DESTINATIONS to bind, and feeds every polled envelope into the registry. A clear error
 * when no broker consumer is bound (memory/queue providers have no long-running consumer — queue uses
 * `php artisan queue:work`).
 *
 * 🔴 DESTINATIONS, NOT EVENT TYPES. This command used to bind the compiled #[EventListener] patterns as broker
 * routes — `$consumer->subscribe((new TopicSubscriptionResolver)->resolve($manifest))` where resolve() returned
 * `['order.*']`. Publishers route by destination (Kafka topic, AMQP exchange/routing key, outbox column), so an app
 * publishing `order.created` to the topic `orders` produced a worker bound to a route nothing writes to: it started
 * cleanly, polled forever and consumed nothing. The destinations now come from `--destination` (repeatable), else
 * the `firefly.eda.destinations` config list, else the safe catch-all — see TopicSubscriptionResolver for the
 * precedence rule and why over-subscribing is the correct default. The bound destinations are echoed at startup so
 * a misconfigured worker is visible in the log on line one instead of being diagnosed as "the broker is down".
 */
final class ConsumeEventsCommand extends Command
{
    /** @var string */
    protected $signature = 'firefly:eda:consume {--destination=* : broker destination(s) to bind; overrides firefly.eda.destinations} {--max-messages= : stop after N messages} {--time-limit= : stop after N seconds} {--sleep=0 : idle ms between empty polls} {--poll-timeout=5000 : block ms per poll}';

    /** @var string */
    protected $description = 'Run the configured broker EventConsumer, dispatching to #[EventListener] handlers until stopped.';

    public function handle(): int
    {
        if (! $this->laravel->bound(EventConsumer::class)) {
            $this->error('No broker EventConsumer is bound. Install firefly/eda-{rabbitmq,postgres,kafka} and set firefly.eda.provider accordingly (the memory/queue providers have no consumer loop; queue uses `php artisan queue:work`).');

            return self::FAILURE;
        }

        /** @var EventConsumer $consumer */
        $consumer = $this->laravel->make(EventConsumer::class);
        /** @var SubscriberRegistry $registry */
        $registry = $this->laravel->make(SubscriberRegistry::class);
        /** @var EventListenerManifest $manifest */
        $manifest = $this->laravel->make(EventListenerManifest::class);

        $destinations = (new TopicSubscriptionResolver)->resolve($manifest, $this->requestedDestinations());
        $consumer->subscribe($destinations);
        $this->info('firefly:eda:consume — subscribed to '.implode(', ', $destinations).'.');

        $maxMessages = $this->option('max-messages');
        $timeLimit = $this->option('time-limit');

        $options = new ConsumerOptions(
            maxMessages: is_numeric($maxMessages) ? (int) $maxMessages : null,
            timeLimit: is_numeric($timeLimit) ? (int) $timeLimit : null,
            pollTimeoutMs: (int) $this->option('poll-timeout'),
            idleSleepMs: (int) $this->option('sleep'),
        );

        $processed = (new ConsumerLoop)->run(
            $consumer,
            fn (EventEnvelope $envelope) => $registry->deliver($envelope),
            $options,
        );

        $this->info("firefly:eda:consume — processed {$processed} message(s).");

        return self::SUCCESS;
    }

    /**
     * The operator's explicit destinations: `--destination` wins over `firefly.eda.destinations` so one compiled
     * manifest can be sharded across several workers (one destination each) without a per-deployment config edit —
     * the `queue:work --queue=` idiom. Both are validated element-by-element and rejected loudly rather than
     * filtered: a destination silently dropped for being an int or a nested array is a subscription gap, and a
     * subscription gap in this command is invisible at runtime (the worker simply never receives those events),
     * which is the whole class of bug this file exists to close.
     *
     * @return list<string>
     */
    private function requestedDestinations(): array
    {
        $option = $this->option('destination');
        if (is_array($option) && $option !== []) {
            return $this->strings($option, '--destination');
        }

        /** @var Config $config */
        $config = $this->laravel->make(Config::class);
        $configured = $config->get('firefly.eda.destinations', []);

        if (! is_array($configured)) {
            throw new ConfigurationException('firefly.eda.destinations must be a list of broker destination strings.');
        }

        return $this->strings($configured, 'firefly.eda.destinations');
    }

    /**
     * @param  array<mixed>  $values
     * @return list<string>
     */
    private function strings(array $values, string $source): array
    {
        $strings = [];
        foreach ($values as $value) {
            if (! is_string($value)) {
                throw new ConfigurationException(
                    "{$source} must contain only broker destination strings; got ".get_debug_type($value).'.',
                );
            }
            $strings[] = $value;
        }

        return $strings;
    }
}
