<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags;

use Firefly\FeatureFlags\Context\EvaluationContextResolver;
use Firefly\FeatureFlags\Evaluation\FlagType;
use Firefly\FeatureFlags\Registry\FlagDocumentSource;
use Firefly\Kernel\Version;
use OpenFeature\implementation\flags\EvaluationOptions;
use OpenFeature\implementation\hooks\HookHints;
use OpenFeature\interfaces\flags\API;
use OpenFeature\interfaces\flags\Client;
use OpenFeature\interfaces\hooks\Hook;
use OpenFeature\interfaces\provider\Provider;
use OpenFeature\OpenFeatureAPI;
use Throwable;

/**
 * The application's door to its flags. Every call resolves the ambient evaluation context (principal, roles,
 * tenant, application, profiles, your contributors), lays the caller's explicit context over it (its `targetingKey`
 * — a string, an int such as a user id, or a Stringable — replaces the principal's key) and evaluates through the
 * OpenFeature client named `firefly`, which carries the metrics and exposure hooks — so a flag read here is
 * counted, exposed and evaluated exactly like one read by any OpenFeature client. Missing or broken flags answer
 * the caller's default.
 *
 * Each typed getter evaluates with its own type: getFloat($key, 1) is a float evaluation (PHP widens the int), and
 * a flag of another type is TYPE_MISMATCH, answered with the default — a boolean is never a number. details()
 * takes the type of its default instead.
 *
 * Values are the caller's own: an object flag answers plain arrays that share nothing with the stored definition.
 *
 * A details() call with `ambient: false` is a PREVIEW (the management `evaluate` action, CONTRACT.md I-3): it
 * evaluates with the explicit context and targeting key plus the process attributes `application` and `profiles`
 * only — no other contributor runs, so the caller's principal, roles and tenant never reach it — and it carries the
 * hook hint PREVIEW_HINT (`firefly.preview` => true), on which the Firefly hooks record neither a metric nor an
 * exposure event. A hook of your own can read it the same way (`$hints->get(FeatureFlags::PREVIEW_HINT) === true`).
 * The SDK still merges the OpenFeature API's and the client's own evaluation contexts underneath (Firefly sets
 * neither), as PyFly's preview does.
 *
 * The provider this application configured is (re)asserted on the OpenFeature API before evaluating: the global API
 * is a process-wide singleton, and a second application booted in the same process (a test suite) must not
 * evaluate through the first one's provider.
 */
final class FeatureFlags
{
    public const string CLIENT_NAME = 'firefly';

    /** The hook hint of a preview evaluation: Firefly's hooks record no metric and no exposure event for it. */
    public const string PREVIEW_HINT = 'firefly.preview';

    private ?Client $client = null;

    /**
     * @param  list<Hook>  $hooks  attached to the `firefly` client only, so a reboot never accumulates them
     */
    public function __construct(
        private readonly Provider $provider,
        private readonly EvaluationContextResolver $context,
        private readonly array $hooks = [],
        private readonly ?FlagDocumentSource $documents = null,
        private readonly ?API $api = null,
    ) {}

    public function client(): Client
    {
        $api = $this->api ?? OpenFeatureAPI::getInstance();
        if ($api->getProvider() !== $this->provider) {
            $api->setProvider($this->provider);
        }

        if ($this->client === null) {
            $client = $api->getClient(self::CLIENT_NAME, Version::VERSION);
            $client->setHooks([...$client->getHooks(), ...$this->hooks]);
            $this->client = $client;
        }

        return $this->client;
    }

    public function provider(): Provider
    {
        return $this->provider;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function isEnabled(string $key, bool $default = false, array $context = [], ?string $targetingKey = null): bool
    {
        return $this->client()->getBooleanValue($key, $default, $this->context->resolve($context, $targetingKey)) ?? $default;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function getString(string $key, string $default, array $context = [], ?string $targetingKey = null): string
    {
        return $this->client()->getStringValue($key, $default, $this->context->resolve($context, $targetingKey)) ?? $default;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function getInt(string $key, int $default, array $context = [], ?string $targetingKey = null): int
    {
        return $this->client()->getIntegerValue($key, $default, $this->context->resolve($context, $targetingKey));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function getFloat(string $key, float $default, array $context = [], ?string $targetingKey = null): float
    {
        return $this->client()->getFloatValue($key, $default, $this->context->resolve($context, $targetingKey));
    }

    /**
     * @param  array<array-key, mixed>  $default
     * @param  array<string, mixed>  $context
     * @return array<array-key, mixed>
     */
    public function getObject(string $key, array $default = [], array $context = [], ?string $targetingKey = null): array
    {
        return $this->client()->getObjectValue($key, $default, $this->context->resolve($context, $targetingKey));
    }

    /**
     * The whole evaluation; the type is the default's type. `ambient: false` is a preview (see the class).
     *
     * @param  bool|string|int|float|array<array-key, mixed>  $default
     * @param  array<string, mixed>  $context
     */
    public function details(string $key, bool|string|int|float|array $default, array $context = [], ?string $targetingKey = null, bool $ambient = true): FlagEvaluation
    {
        $evaluationContext = $this->context->resolve($context, $targetingKey, $ambient);
        $options = $ambient ? null : new EvaluationOptions([], new HookHints([self::PREVIEW_HINT => true]));
        $client = $this->client();

        $details = match (true) {
            is_bool($default) => $client->getBooleanDetails($key, $default, $evaluationContext, $options),
            is_string($default) => $client->getStringDetails($key, $default, $evaluationContext, $options),
            is_int($default) => $client->getIntegerDetails($key, $default, $evaluationContext, $options),
            is_float($default) => $client->getFloatDetails($key, $default, $evaluationContext, $options),
            default => $client->getObjectDetails($key, $default, $evaluationContext, $options),
        };

        return FlagEvaluation::fromDetails($key, $details, $this->metadata($key));
    }

    /**
     * The variant the flag resolves to, evaluated with the flag's own type when the flag is Firefly's (string
     * evaluation otherwise); null when the flag is missing, disabled, has no matching variant or fails.
     *
     * @param  array<string, mixed>  $context
     */
    public function variant(string $key, array $context = [], ?string $targetingKey = null): ?string
    {
        $type = $this->flagType($key) ?? FlagType::String;
        $evaluation = $this->details($key, $type->zero(), $context, $targetingKey);

        return $evaluation->errorCode === null ? $evaluation->variant : null;
    }

    private function flagType(string $key): ?FlagType
    {
        try {
            return $this->documents?->document()->flag($key)?->flagType();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The flag's metadata over the document's (scalars only), or none for a flag Firefly does not hold.
     *
     * @return array<array-key, bool|int|float|string>
     */
    private function metadata(string $key): array
    {
        try {
            $document = $this->documents?->document();
        } catch (Throwable) {
            return [];
        }

        $flag = $document?->flag($key);
        if ($document === null || $flag === null) {
            return [];
        }

        $shared = array_filter($document->metadata, static fn (mixed $value): bool => is_bool($value) || is_int($value) || is_float($value) || is_string($value));

        return array_replace($shared, $flag->metadata());
    }
}
