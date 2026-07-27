<?php

declare(strict_types=1);

namespace Firefly\Eda\Consumer;

use Firefly\Eda\Listener\EventListenerManifest;

/**
 * The fnmatch->concrete-topic bridge. EventPublisher::subscribe() is fnmatch-pattern-based; a real broker subscribes
 * to concrete topics/queues. This collects the distinct event-type patterns the compiled #[EventListener]s declare;
 * each adapter maps them to its own subscription model (RabbitMQ topic bindings, a Kafka topic list, a Postgres
 * LISTEN channel) and relies on SubscriberRegistry's fnmatch for the fine-grained post-receipt filter.
 */
final class TopicSubscriptionResolver
{
    /**
     * @return list<string>
     */
    public function resolve(EventListenerManifest $manifest): array
    {
        $patterns = [];
        foreach ($manifest->all() as $descriptor) {
            foreach ($descriptor->patterns as $pattern) {
                if (! in_array($pattern, $patterns, true)) {
                    $patterns[] = $pattern;
                }
            }
        }

        return $patterns;
    }
}
