<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Source;

use DateTimeInterface;
use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnMissingBean;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\FeatureFlags\Definition\FlagDefinitions;
use Firefly\FeatureFlags\Definition\InvalidFlagDefinition;
use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\FeatureFlagsSettings;
use JsonException;
use OpenFeature\interfaces\provider\Provider;
use stdClass;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * A flagd document on disk (.json, .yaml, .yml), re-read when its mtime or size changes (checked at most once
 * per `sources.file.refresh-interval` across all workers). Plain flagd only: shorthand belongs to config. A
 * relative `path` is relative to the PHP working directory.
 *
 * Only an absent document — JSON `null`, an empty or comment-only YAML file — is empty; any other top level that is
 * not an object (`[]`, `0`, `false`, a string) is refused with the key `<document>`. A file that does not parse is
 * refused as a whole with the key `<document>`, and the reason names the file.
 *
 * Symfony Yaml reads only `true`/`false` as booleans, as YAML 1.2 and flagd do, so `on`/`off` stay variant names.
 * Two more YAML readings are brought back to what the same JSON file would say. An unquoted date
 * (`expires: 2025-01-01`) is text, as flagd expects — Symfony Yaml would otherwise give an int timestamp — and a
 * timestamp is ISO-8601 text the way PyFly writes one (seconds, microseconds only when non-zero, the offset only
 * when the file wrote one). A PHP tag (`!php/const`, `!php/object`) is refused instead of read as null.
 *
 * Known divergence from PyFly: an unquoted date as a block-mapping KEY (`2024-01-01: x`) reaches this class as the
 * int key 1704067200 — Symfony Yaml evaluates block keys without the parse flags, so nothing tells it apart from a
 * key written `1704067200:` — and is read as the text "1704067200" where PyFly refuses the key. As a flow-mapping
 * key (`{2024-01-01: x}`) it is refused as invalid YAML. Quote date keys.
 *
 * The revision is `mtime-size` at whole seconds (PHP's stat()): an edit that keeps the size and lands within the
 * same second as the previous one goes unseen until the mtime or the size moves again. The revision is read before
 * the contents, so it never claims newer bytes than the ones parsed.
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.feature-flags.enabled', havingValue: 'true')]
#[ConditionalOnProperty(name: 'firefly.feature-flags.sources.file.enabled', havingValue: 'true')]
#[ConditionalOnMissingBean(Provider::class)]
final class FileFlagSource implements FlagSource
{
    private const int YAML_FLAGS = Yaml::PARSE_OBJECT_FOR_MAP | Yaml::PARSE_DATETIME | Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE;

    public function __construct(private readonly FeatureFlagsSettings $settings) {}

    public function name(): string
    {
        return self::FILE;
    }

    public function precedence(): int
    {
        return 200;
    }

    public function refreshInterval(): float
    {
        return $this->settings->file->refreshInterval;
    }

    public function failsStartup(): bool
    {
        return true;
    }

    public function reportedRevision(?string $revision): ?string
    {
        return $revision;
    }

    public function load(?string $knownRevision): ?SourceSnapshot
    {
        $path = $this->settings->file->path;
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (! in_array($extension, ['json', 'yaml', 'yml'], true)) {
            throw new FlagSourceUnavailable("The flag file [{$path}] must end in .json, .yaml or .yml.");
        }

        clearstatcache(true, $path);
        $stat = is_file($path) ? @stat($path) : false;
        if ($stat === false) {
            throw new FlagSourceUnavailable("The flag file [{$path}] does not exist or cannot be read.");
        }

        $revision = $stat['mtime'].'-'.$stat['size'];
        if ($revision === $knownRevision) {
            return null;
        }

        $contents = @file_get_contents($path);
        if ($contents === false) {
            throw new FlagSourceUnavailable("The flag file [{$path}] cannot be read.");
        }

        $document = self::decode($path, $extension, $contents) ?? new stdClass; // only an absent document is empty

        return new SourceSnapshot(FlagDefinitions::parseDocument($document), $revision);
    }

    /**
     * The document as JSON would decode it; null for an absent one.
     *
     * @throws InvalidFlagDefinition the file does not parse
     */
    private static function decode(string $path, string $extension, string $contents): mixed
    {
        try {
            return $extension === 'json'
                ? Json::decode($contents)
                : Json::normalize(self::datesAsText(Yaml::parse($contents, self::YAML_FLAGS)));
        } catch (JsonException|ParseException $failure) {
            throw new InvalidFlagDefinition('<document>', sprintf(
                'the flag file [%s] is not valid %s (%s)',
                $path,
                $extension === 'json' ? 'JSON' : 'YAML',
                rtrim($failure->getMessage(), '.'),
            ));
        }
    }

    /** Every YAML date or timestamp in $value replaced by its text, at any depth. */
    private static function datesAsText(mixed $value): mixed
    {
        if ($value instanceof DateTimeInterface) {
            return self::isoText($value);
        }

        if ($value instanceof stdClass) {
            $members = new stdClass;
            foreach (get_object_vars($value) as $name => $member) {
                $members->{$name} = self::datesAsText($member);
            }

            return $members;
        }

        return is_array($value) ? array_map(self::datesAsText(...), $value) : $value;
    }

    /**
     * `2025-01-01` for a date; otherwise Python's isoformat(), which PyFly writes: `2025-01-01T10:30:00`, with
     * `.ffffff` when there are microseconds and the offset when the file wrote one. Symfony Yaml places a timestamp
     * written without an offset in the zone named "UTC" (an offset written as `Z` or `+00:00` keeps that name), and
     * reads a date as such a timestamp at midnight.
     */
    private static function isoText(DateTimeInterface $at): string
    {
        $offsetWritten = $at->getTimezone()->getName() !== 'UTC';
        if (! $offsetWritten && $at->format('H:i:s.u') === '00:00:00.000000') {
            return $at->format('Y-m-d');
        }

        return $at->format('Y-m-d\TH:i:s')
            .($at->format('u') === '000000' ? '' : $at->format('.u'))
            .($offsetWritten ? $at->format('P') : '');
    }
}
