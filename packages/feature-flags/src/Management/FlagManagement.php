<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Management;

use Closure;
use Firefly\FeatureFlags\Context\EvaluationContextResolver;
use Firefly\FeatureFlags\Definition\FlagDefinition;
use Firefly\FeatureFlags\Definition\InvalidFlagDefinition;
use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Evaluation\FlagType;
use Firefly\FeatureFlags\FeatureFlagsSettings;
use Firefly\FeatureFlags\FlagEvaluation;
use Firefly\FeatureFlags\Provider\FireflyFlagProvider;
use Firefly\FeatureFlags\Registry\ComposedFlag;
use Firefly\FeatureFlags\Registry\FlagRegistry;
use Firefly\FeatureFlags\Store\FlagChange;
use Firefly\FeatureFlags\Store\FlagStoreConflict;
use Firefly\FeatureFlags\Store\FlagStoreWriter;
use OpenFeature\interfaces\provider\Provider;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use stdClass;
use Throwable;

/**
 * The §4.8 operations behind the `flags` actuator endpoint, the admin page and `firefly:flags` — one
 * implementation, so the three surfaces cannot drift. Bodies are returned in Json's faithful form (objects that
 * must stay objects are stdClass) and encoded by the caller with Json::encode().
 *
 * Writes need firefly.feature-flags.management.writes (else `writes-disabled`) and a store (else `not-writable`).
 * `enable`, `disable` and `default-variant` on a key the store does not hold copy the current effective
 * definition — without test overrides — into the store with the change applied. The `evaluate` preview uses the
 * caller's context, the targeting key and the application/profile attributes only — never the caller's own
 * principal — and runs no hooks, so a preview records no metric and no exposure event.
 */
final class FlagManagement
{
    public const int HISTORY_LIMIT = 50;

    /** @var list<string> */
    public const array WRITE_ACTIONS = ['enable', 'disable', 'default-variant', 'put', 'delete'];

    /** @var Closure(): string */
    private readonly Closure $today;

    /**
     * @param  list<FlagActorSource>  $actors
     * @param  (Closure(): string)|null  $today  the UTC date, Y-m-d
     */
    public function __construct(
        private readonly FeatureFlagsSettings $settings,
        private readonly Provider $provider,
        private readonly ?FlagRegistry $registry = null,
        private readonly ?FlagStoreWriter $writer = null,
        private readonly EvaluationContextResolver $preview = new EvaluationContextResolver,
        private readonly array $actors = [],
        ?Closure $today = null,
        private readonly LoggerInterface $logger = new NullLogger,
    ) {
        $this->today = $today ?? static fn (): string => gmdate('Y-m-d');
    }

    public function writable(): bool
    {
        return $this->writer !== null;
    }

    public function writesEnabled(): bool
    {
        return $this->settings->writes;
    }

    /**
     * `GET /actuator/flags`.
     *
     * @return array{provider: array{name: string, status: string}, writable: bool, writesEnabled: bool, sources: list<array<string, mixed>>, flags: list<array<string, mixed>>}
     */
    public function overview(): array
    {
        $sources = [];
        $flags = [];

        if ($this->registry !== null) {
            $composition = $this->registry->composition();
            foreach ($this->registry->states() as $state) {
                $sources[] = [
                    'name' => $state->name,
                    'enabled' => true,
                    'status' => $state->status(),
                    'flags' => $state->flags,
                    'lastRefresh' => $state->lastRefresh,
                    'error' => $state->error,
                    'revision' => $this->registry->source($state->name)?->reportedRevision($state->revision),
                ];
            }

            $versions = [];
            try {
                foreach ($this->writer?->store()->all() ?? [] as $stored) {
                    $versions[$stored->key] = $stored->version;
                }
            } catch (Throwable $failure) {
                // An operator can still inspect the last-good composition during a store outage.
                $this->warn('Flag store versions are unavailable for the management overview: {error}.', null, $failure);
            }

            $today = ($this->today)();
            foreach ($composition->flags as $flag) {
                $flags[] = $this->summary($flag, $versions[$flag->definition->key] ?? null, $today);
            }
        }

        return [
            'provider' => ['name' => $this->provider->getMetadata()->getName(), 'status' => 'READY'],
            'writable' => $this->writable(),
            'writesEnabled' => $this->writesEnabled(),
            'sources' => $sources,
            'flags' => $flags,
        ];
    }

    /**
     * `GET /actuator/flags/{key}`.
     *
     * @return array{key: string, definition: stdClass|array<array-key, mixed>, origin: string, layers: list<array{source: string, definition: stdClass|array<array-key, mixed>}>, version: int|null, expired: bool, history: list<array{id: int, action: string, actor: ?string, changedAt: string}>}
     *
     * @throws FlagManagementException
     */
    public function describe(string $key): array
    {
        $flag = $this->composed($key);
        $store = $this->writer?->store();
        $version = null;
        $history = [];
        if ($store !== null) {
            try {
                $version = $store->get($key)?->version;
                $history = array_map(static fn (FlagChange $change): array => $change->toHistoryRow(), $store->history($key, self::HISTORY_LIMIT));
            } catch (Throwable $failure) {
                // Store diagnostics must not obscure a visible last-good definition.
                $this->warn('Flag store diagnostics are unavailable for [{key}]: {error}.', $key, $failure);
            }
        }

        return [
            'key' => $key,
            'definition' => $flag->definition->toJsonValue(),
            'origin' => $flag->origin,
            'layers' => array_map(
                static fn (array $layer): array => ['source' => $layer['source'], 'definition' => $layer['definition']->toJsonValue()],
                $flag->layers,
            ),
            'version' => $version,
            'expired' => $flag->definition->isExpired(($this->today)()),
            'history' => $history,
        ];
    }

    /**
     * The `evaluate` action: explicit context only (plus application/profiles), no hooks.
     *
     * @param  array<string, mixed>  $context
     * @return array{key: string, value: mixed, variant: ?string, reason: string, errorCode: ?string, metadata: stdClass|array<array-key, bool|int|float|string>}
     *
     * @throws FlagManagementException
     */
    public function evaluate(string $key, array $context = [], ?string $targetingKey = null): array
    {
        $evaluationContext = $this->preview->resolve($context, $targetingKey, ambient: false);

        if ($this->provider instanceof FireflyFlagProvider && $this->registry !== null) {
            $type = $this->composed($key)->definition->flagType();

            $default = $type === FlagType::Object ? new stdClass : $type->zero();

            return FlagEvaluation::fromResolution($key, $this->provider->resolution($key, $type, $default, $evaluationContext))->toArray();
        }

        // External-provider mode: Firefly knows no flag types, so the preview is a boolean evaluation, default false.
        $details = $this->provider->resolveBooleanValue($key, false, $evaluationContext);
        $error = $details->getError();

        return (new FlagEvaluation(
            $key,
            $details->getValue(),
            $error === null ? $details->getVariant() : null,
            $error === null ? ($details->getReason() ?? 'UNKNOWN') : 'ERROR',
            $error?->getResolutionErrorCode()->getValue(),
            $error?->getResolutionErrorMessage(),
        ))->toArray();
    }

    /**
     * `POST /actuator/flags/{key}`: `evaluate`, or one of the WRITE_ACTIONS. Answers the key's describe() body
     * after a write, or `{"key", "deleted": true}` when a delete leaves no layer defining the key.
     *
     * @param  mixed  $body  the decoded request body (Json's faithful form, or a plain array from a form)
     * @param  string  $fallbackActor  recorded when no FlagActorSource names the caller
     * @return array<string, mixed>
     *
     * @throws FlagManagementException
     */
    public function apply(string $key, mixed $body, string $fallbackActor): array
    {
        if (! Json::isObject($body)) {
            throw new FlagManagementException(ManagementError::BadRequest, 'The request body must be a JSON object naming an action.');
        }

        $members = Json::members($body);
        $action = $members['action'] ?? null;
        if (! is_string($action) || ($action !== 'evaluate' && ! in_array($action, self::WRITE_ACTIONS, true))) {
            throw new FlagManagementException(ManagementError::BadRequest, 'The action must be one of evaluate, '.implode(', ', self::WRITE_ACTIONS).'.');
        }

        if ($action === 'evaluate') {
            return $this->evaluate($key, self::context($members['context'] ?? null), self::targetingKey($members['targetingKey'] ?? null));
        }

        $writer = $this->writer();
        $expected = self::expectedVersion($members['expectedVersion'] ?? null);
        $actor = $this->actor($fallbackActor);

        try {
            switch ($action) {
                case 'put':
                    if (! array_key_exists('definition', $members)) {
                        throw new FlagManagementException(ManagementError::BadRequest, 'put needs a definition: one flagd flag object.');
                    }
                    $writer->put($key, $members['definition'], $actor, $expected);
                    break;

                case 'delete':
                    if ($writer->store()->get($key) === null) {
                        throw new FlagManagementException(ManagementError::UnknownFlag, "The store holds no flag [{$key}]; only a stored flag can be deleted.");
                    }
                    $writer->delete($key, $actor, $expected);
                    break;

                default:
                    $writer->put($key, $this->changed($key, $action, $members), $actor, $expected);
            }
        } catch (InvalidFlagDefinition $invalid) {
            throw new FlagManagementException(ManagementError::InvalidDefinition, $invalid->getMessage(), $invalid);
        } catch (FlagStoreConflict $conflict) {
            throw new FlagManagementException(ManagementError::Conflict, $conflict->getMessage(), $conflict);
        }

        try {
            return $this->describe($key);
        } catch (Throwable $failure) {
            $this->warn('Flag management detail is unavailable after an accepted write to [{key}]: {error}.', $key, $failure);

            return ['key' => $key, $action === 'delete' ? 'deleted' : 'refreshPending' => true];
        }
    }

    /**
     * The definition `enable`, `disable` or `default-variant` writes: the stored one, or else the effective one.
     *
     * @param  array<array-key, mixed>  $members
     * @return array<array-key, mixed>
     *
     * @throws FlagManagementException
     */
    private function changed(string $key, string $action, array $members): array
    {
        $stored = $this->writer?->store()->get($key);
        $composed = $this->registry?->composition(false)->flag($key);
        $base = $stored !== null
            ? FlagDefinition::fromJsonValue($key, $stored->definition)
            : ($composed === null
                ? throw new FlagManagementException(ManagementError::UnknownFlag, "No layer defines flag [{$key}].")
                : $composed->definition);

        $definition = Json::members($base->toJsonValue());

        if ($action === 'default-variant') {
            $variant = $members['variant'] ?? null;
            if (! is_string($variant) || $variant === '') {
                throw new FlagManagementException(ManagementError::BadRequest, 'default-variant needs a variant: the name of one of the flag\'s variants.');
            }
            if (! $base->hasVariant($variant)) {
                throw new FlagManagementException(ManagementError::UnknownVariant, "Flag [{$key}] has no variant [{$variant}]; its variants are ".implode(', ', $base->variantNames()).'.');
            }
            $definition['defaultVariant'] = $variant;

            return $definition;
        }

        $definition['state'] = $action === 'enable' ? 'ENABLED' : 'DISABLED';

        return $definition;
    }

    /**
     * @throws FlagManagementException
     */
    private function composed(string $key): ComposedFlag
    {
        return $this->registry?->composition()->flag($key)
            ?? throw new FlagManagementException(ManagementError::UnknownFlag, "No layer defines flag [{$key}].");
    }

    /**
     * @throws FlagManagementException
     */
    private function writer(): FlagStoreWriter
    {
        if (! $this->settings->writes) {
            throw new FlagManagementException(ManagementError::WritesDisabled, 'Flag writes are disabled: set firefly.feature-flags.management.writes to true.');
        }

        return $this->writer
            ?? throw new FlagManagementException(ManagementError::NotWritable, 'No flag store is configured: enable firefly.feature-flags.sources.store to make flags writable.');
    }

    private function actor(string $fallback): string
    {
        foreach ($this->actors as $source) {
            try {
                $actor = $source->actor();
            } catch (Throwable) {
                $actor = null;
            }

            if ($actor !== null && $actor !== '') {
                return $actor;
            }
        }

        return $fallback;
    }

    private function warn(string $message, ?string $key, Throwable $failure): void
    {
        try {
            $this->logger->warning($message, ['key' => $key, 'error' => $failure->getMessage()]);
        } catch (Throwable) {
            // A diagnostic failure or logger failure cannot undo an accepted write.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(ComposedFlag $flag, ?int $version, string $today): array
    {
        $definition = $flag->definition;

        return [
            'key' => $definition->key,
            'state' => $definition->state(),
            'type' => $definition->valueType(),
            'variants' => $definition->variantNames(),
            'defaultVariant' => $definition->defaultVariant(),
            'targeting' => $definition->targeting() !== null,
            'origin' => $flag->origin,
            'overrides' => $flag->overrides,
            'metadata' => Json::object($definition->metadata()),
            'expired' => $definition->isExpired($today),
            'version' => $version,
        ];
    }

    /**
     * @return array<string, mixed>
     *
     * @throws FlagManagementException
     */
    private static function context(mixed $context): array
    {
        if ($context === null || $context === []) {
            return [];
        }

        if (! Json::isObject($context)) {
            throw new FlagManagementException(ManagementError::BadRequest, 'context must be an object of evaluation attributes.');
        }

        $attributes = [];
        foreach (Json::members($context) as $name => $value) {
            $attributes[(string) $name] = Json::toPhp($value);
        }

        return $attributes;
    }

    /**
     * @throws FlagManagementException
     */
    private static function targetingKey(mixed $targetingKey): ?string
    {
        if ($targetingKey !== null && ! is_string($targetingKey)) {
            throw new FlagManagementException(ManagementError::BadRequest, 'targetingKey must be a string.');
        }

        return $targetingKey;
    }

    /**
     * @throws FlagManagementException
     */
    private static function expectedVersion(mixed $expected): ?int
    {
        if ($expected !== null && (! is_int($expected) || $expected < 0)) {
            throw new FlagManagementException(ManagementError::BadRequest, 'expectedVersion must be a non-negative integer.');
        }

        return $expected;
    }
}
