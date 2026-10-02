<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Support;

use Firefly\FeatureFlags\Definition\FlagDocument;
use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Evaluation\FlagdEvaluator;
use Firefly\FeatureFlags\Evaluation\FlagType;
use Firefly\FeatureFlags\Evaluation\Resolution;

/**
 * Executes one testbed scenario's steps against a FlagdEvaluator and returns every mismatch (empty = pass).
 * Value regexes are greedy (`"(.*)"$`): a fallback may itself contain quotes. Object cells escape quotes as \".
 */
final class GherkinRunner
{
    public function __construct(private readonly FlagdEvaluator $evaluator, private readonly FlagDocument $document) {}

    /**
     * @param  list<string>  $steps
     * @return list<string>
     */
    public function run(array $steps): array
    {
        $failures = [];
        $flag = null;
        $attributes = [];
        $targetingKey = null;
        $resolution = null;

        foreach ($steps as $step) {
            $body = (string) preg_replace('/^(Given|When|Then|And)\s+/', '', $step);

            if ($body === 'an evaluator') {
                continue;
            }
            if (preg_match('/^an? (\w+)-flag with key "([^"]*)" and a fallback value "(.*)"$/', $body, $m) === 1) {
                $type = FlagType::from(strtolower($m[1]));
                $flag = [$type, $m[2], self::coerce($type->value, $m[3])];

                continue;
            }
            if (preg_match('/^a context containing a key "([^"]*)", with type "([^"]*)" and with value "(.*)"$/', $body, $m) === 1) {
                $attributes[$m[1]] = self::coerce(strtolower($m[2]), $m[3]);

                continue;
            }
            if (preg_match('/^a context containing a targeting key with value "([^"]*)"/', $body, $m) === 1) {
                $targetingKey = $m[1];

                continue;
            }
            if (preg_match('/^a context containing a nested property with outer key "([^"]*)" and inner key "([^"]*)", with value "([^"]*)"/', $body, $m) === 1) {
                $outer = is_array($attributes[$m[1]] ?? null) ? $attributes[$m[1]] : [];
                $outer[$m[2]] = $m[3];
                $attributes[$m[1]] = $outer;

                continue;
            }
            if ($body === 'the flag was evaluated with details') {
                if ($flag === null) {
                    return ['evaluated before a flag was named'];
                }
                $resolution = $this->evaluator->evaluate($this->document, $flag[1], $flag[0], $flag[2], $targetingKey, $attributes);

                continue;
            }
            if (! $resolution instanceof Resolution || $flag === null) {
                return ["step before evaluation: {$body}"];
            }
            if (preg_match('/^the resolved details value should be "(.*)"$/', $body, $m) === 1) {
                $expected = self::coerce($flag[0]->value, $m[1]);
                if (Json::canonical($resolution->value) !== Json::canonical($expected)) {
                    $failures[] = sprintf('value %s != %s', Json::canonical($resolution->value), Json::canonical($expected));
                }

                continue;
            }
            if (preg_match('/^the reason should be "([^"]*)"/', $body, $m) === 1) {
                if ($resolution->reason->value !== $m[1]) {
                    $failures[] = "reason {$resolution->reason->value} != {$m[1]}";
                }

                continue;
            }
            if (preg_match('/^the error-code should be "([^"]*)"/', $body, $m) === 1) {
                if ($resolution->error?->value !== $m[1]) {
                    $failures[] = 'error '.($resolution->error->value ?? 'none')." != {$m[1]}";
                }

                continue;
            }
            if (str_starts_with($body, 'the resolved metadata is empty')) {
                if ($resolution->metadata !== []) {
                    $failures[] = 'metadata is not empty: '.Json::encode($resolution->metadata);
                }

                continue;
            }
            if (str_starts_with($body, 'the resolved metadata should contain')) {
                $rows = array_slice(explode("\n", $body), 1);
                $header = array_map('trim', explode('|', trim((string) array_shift($rows), '| ')));
                foreach ($rows as $row) {
                    $cells = array_combine($header, array_map('trim', explode('|', trim($row, '| '))));
                    $key = $cells['key'] ?? '';
                    $expected = self::coerce(strtolower($cells['metadata_type'] ?? ''), $cells['value'] ?? '');
                    if (($resolution->metadata[$key] ?? null) !== $expected) {
                        $failures[] = "metadata [{$key}] != ".Json::encode($expected);
                    }
                }

                continue;
            }

            $failures[] = "unknown step: {$body}";
        }

        return $failures;
    }

    private static function coerce(string $type, string $value): mixed
    {
        return match ($type) {
            'boolean' => $value === 'true',
            'integer' => (int) $value,
            'float' => (float) $value,
            'object' => Json::decode(str_replace('\\"', '"', $value)),
            default => $value,
        };
    }
}
