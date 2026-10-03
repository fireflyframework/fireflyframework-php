<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Source;

use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\FeatureFlags\Definition\FlagDefinitions;
use Firefly\FeatureFlags\FeatureFlagsSettings;
use OpenFeature\interfaces\provider\Provider;

/**
 * `firefly.feature-flags.flags` + `.evaluators`: the lowest layer, and the only one that accepts shorthand.
 * Its revision is a hash of the two maps, so the (cached) validation runs again only when the configuration does.
 *
 * The two maps reach the shared validation as written: `[]` is an empty section (PHP configuration cannot write
 * `{}`), and a non-empty list is refused (`flags must be an object`, `$evaluators must be an object`) rather than
 * read as flags named "0", "1"… — so flags named only "0", "1", "2"… in that order cannot be written here (PHP
 * stores such a map as a list). A value that is not an array never gets this far: FeatureFlagsSettings refuses it at
 * boot, naming the key.
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.feature-flags.enabled', havingValue: 'true')]
#[ConditionalOnMissingBean(Provider::class)]
final class ConfigFlagSource implements FlagSource
{
    public function __construct(private readonly FeatureFlagsSettings $settings) {}

    public function name(): string
    {
        return self::CONFIG;
    }

    public function precedence(): int
    {
        return 100;
    }

    public function refreshInterval(): float
    {
        return 0.0;
    }

    public function failsStartup(): bool
    {
        return true;
    }

    public function reportedRevision(?string $revision): ?string
    {
        return null;
    }

    public function load(?string $knownRevision): ?SourceSnapshot
    {
        $revision = hash('sha256', serialize([$this->settings->flags, $this->settings->evaluators]));
        if ($revision === $knownRevision) {
            return null;
        }

        $document = FlagDefinitions::parseDocument([
            'flags' => FlagDefinitions::normalize($this->settings->flags),
            '$evaluators' => $this->settings->evaluators,
        ]);

        return new SourceSnapshot($document, $revision);
    }
}
