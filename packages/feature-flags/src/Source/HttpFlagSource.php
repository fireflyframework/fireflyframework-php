<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Source;

use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\FeatureFlags\Definition\FlagDefinitions;
use Firefly\FeatureFlags\Definition\InvalidFlagDefinition;
use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\FeatureFlagsSettings;
use Illuminate\Http\Client\Factory;
use JsonException;
use OpenFeature\interfaces\provider\Provider;
use Throwable;

/** A remote flagd document polled with the registry's last accepted revision as its ETag. */
#[Component]
#[ConditionalOnProperty(name: 'firefly.feature-flags.enabled', havingValue: 'true')]
#[ConditionalOnProperty(name: 'firefly.feature-flags.sources.http.enabled', havingValue: 'true')]
#[ConditionalOnMissingBean(Provider::class)]
final class HttpFlagSource implements FlagSource
{
    public function __construct(
        private readonly FeatureFlagsSettings $settings,
        private readonly Factory $http,
    ) {}

    public function name(): string
    {
        return self::HTTP;
    }

    public function precedence(): int
    {
        return 300;
    }

    public function refreshInterval(): float
    {
        return $this->settings->http->refreshInterval;
    }

    public function failsStartup(): bool
    {
        return false;
    }

    public function reportedRevision(?string $revision): ?string
    {
        return $revision;
    }

    public function load(?string $knownRevision): ?SourceSnapshot
    {
        $settings = $this->settings->http;
        $request = $this->http->timeout($settings->timeout)->connectTimeout($settings->timeout)->acceptJson();
        if ($settings->token !== '') {
            $request = $request->withToken($settings->token);
        }
        if ($knownRevision !== null) {
            $request = $request->withHeaders(['If-None-Match' => $knownRevision]);
        }

        try {
            $response = $request->get($settings->url);
        } catch (Throwable $failure) {
            throw new FlagSourceUnavailable("The sync endpoint [{$settings->url}] did not answer: {$failure->getMessage()}", 0, $failure);
        }

        if ($response->status() === 304 && $knownRevision !== null) {
            return null;
        }
        if (! $response->successful()) {
            throw new FlagSourceUnavailable("The sync endpoint [{$settings->url}] answered HTTP {$response->status()}.");
        }

        $body = $response->body();
        try {
            $document = FlagDefinitions::parseDocument(Json::decode($body));
        } catch (JsonException $failure) {
            throw new InvalidFlagDefinition('<document>', 'the flag document is not valid JSON ('.$failure->getMessage().')');
        }

        $etag = $response->header('ETag');

        return new SourceSnapshot($document, $etag !== '' ? $etag : '"'.hash('sha256', $body).'"');
    }
}
