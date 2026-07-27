<?php

declare(strict_types=1);

namespace Firefly\Eda\Console;

use Firefly\Eda\Bus\SubscriberRegistry;
use Firefly\Eda\Consumer\ConsumerLoop;
use Firefly\Eda\Consumer\ConsumerOptions;
use Firefly\Eda\Consumer\EventConsumer;
use Firefly\Eda\Consumer\TopicSubscriptionResolver;
use Firefly\Eda\EventEnvelope;
use Firefly\Eda\Listener\EventListenerManifest;
use Illuminate\Console\Command;

/**
 * Drives the active broker's EventConsumer until a bound trips or a signal arrives. Resolves the EventConsumer +
 * SubscriberRegistry a broker package bound (the same registry EventListenerWiringPass populated this process's boot),
 * derives concrete subscriptions from the compiled EventListenerManifest, and feeds every polled envelope into the
 * registry. A clear error when no broker consumer is bound (memory/queue providers have no long-running consumer —
 * queue uses `php artisan queue:work`).
 */
final class ConsumeEventsCommand extends Command
{
    /** @var string */
    protected $signature = 'firefly:eda:consume {--max-messages= : stop after N messages} {--time-limit= : stop after N seconds} {--sleep=0 : idle ms between empty polls} {--poll-timeout=5000 : block ms per poll}';

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

        $consumer->subscribe((new TopicSubscriptionResolver)->resolve($manifest));

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
}
