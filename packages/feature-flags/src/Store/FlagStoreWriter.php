<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Store;

use Firefly\Context\Event\ApplicationEventPublisher;
use Firefly\FeatureFlags\Definition\FlagDefinitions;
use Firefly\FeatureFlags\Definition\InvalidFlagDefinition;
use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Event\FeatureFlagUpdated;
use Firefly\FeatureFlags\Registry\FlagRegistry;
use Firefly\FeatureFlags\Source\FlagSource;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Throwable;

final class FlagStoreWriter
{
    public function __construct(
        private readonly FlagStore $store,
        private readonly FlagRegistry $registry,
        private readonly ApplicationEventPublisher $events,
        private readonly LoggerInterface $logger = new NullLogger,
    ) {}

    public function store(): FlagStore
    {
        return $this->store;
    }

    /** @throws InvalidFlagDefinition|FlagStoreConflict */
    public function put(string $key, mixed $definition, ?string $actor, ?int $expectedVersion = null): FlagChange
    {
        // Parsing validates once and preserves object-shaped values, including an empty object variant.
        $document = FlagDefinitions::parseDocument(['flags' => Json::object([$key => $definition])]);
        $flag = $document->flag($key);
        if ($flag === null) {
            throw new InvalidFlagDefinition($key, 'flag definition must be an object');
        }
        $validated = Json::members(Json::decode(Json::canonical($flag->toJsonValue())));
        $change = $this->store->put($key, $validated, $actor, $expectedVersion);
        $this->afterCommit($change);

        return $change;
    }

    /** @throws FlagStoreConflict */
    public function delete(string $key, ?string $actor, ?int $expectedVersion = null): ?FlagChange
    {
        $change = $this->store->delete($key, $actor, $expectedVersion);
        if ($change !== null) {
            $this->afterCommit($change);
        }

        return $change;
    }

    private function afterCommit(FlagChange $change): void
    {
        // Snapshot now: a SQL callback may run much later, after the caller mutates its input or another write.
        $previous = $change->previous === null ? null : Json::encode(Json::object($change->previous));
        $current = $change->definition === null ? null : Json::encode(Json::object($change->definition));
        $notify = function () use ($change, $previous, $current): void {
            try {
                $this->registry->refresh(force: true, only: FlagSource::STORE);
                foreach ($this->registry->states() as $state) {
                    if ($state->name === FlagSource::STORE && $state->status() !== 'UP') {
                        $this->warn('Feature flag store refresh failed after commit ({error}).', $change, new RuntimeException($state->error ?? $state->status()));
                        break;
                    }
                }
            } catch (Throwable $error) {
                $this->warn('Feature flag store refresh failed after commit ({error}).', $change, $error);
            }

            try {
                $this->events->publish(new FeatureFlagUpdated(
                    $change->key,
                    $change->action,
                    $change->actor,
                    $previous === null ? null : Json::members(Json::decode($previous)),
                    $current === null ? null : Json::members(Json::decode($current)),
                ));
            } catch (Throwable $error) {
                $this->warn('FeatureFlagUpdated listener failed after commit ({error}).', $change, $error);
            }
        };

        if ($this->store instanceof CommitAwareFlagStore) {
            $this->store->afterCommit($notify);
        } else {
            $notify();
        }
    }

    private function warn(string $message, FlagChange $change, Throwable $error): void
    {
        try {
            $this->logger->warning($message, ['key' => $change->key, 'action' => $change->action, 'error' => $error->getMessage()]);
        } catch (Throwable) {
            // Logging is an observer too; the committed change must still return.
        }
    }
}
