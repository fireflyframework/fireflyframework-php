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
use RuntimeException;
use Throwable;

final class FlagStoreWriter
{
    public function __construct(
        private readonly FlagStore $store,
        private readonly FlagRegistry $registry,
        private readonly ApplicationEventPublisher $events,
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
            $failure = null;
            try {
                $this->registry->refresh(force: true, only: FlagSource::STORE);
                foreach ($this->registry->states() as $state) {
                    if ($state->name === FlagSource::STORE && $state->status() !== 'UP') {
                        $failure = new RuntimeException(sprintf('Flag [%s] write committed, but the store source refresh failed: %s', $change->key, $state->error ?? $state->status()));
                        break;
                    }
                }
            } catch (Throwable $error) {
                $failure = $error;
            }

            $this->events->publish(new FeatureFlagUpdated(
                $change->key,
                $change->action,
                $change->actor,
                $previous === null ? null : Json::members(Json::decode($previous)),
                $current === null ? null : Json::members(Json::decode($current)),
            ));
            if ($failure !== null) {
                throw new RuntimeException(sprintf('Flag [%s] write committed, but the registry refresh failed.', $change->key), previous: $failure);
            }
        };

        if ($this->store instanceof CommitAwareFlagStore) {
            $this->store->afterCommit($notify);
        } else {
            $notify();
        }
    }
}
