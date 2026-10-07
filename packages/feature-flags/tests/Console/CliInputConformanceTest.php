<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Tests\Support\FlagsCommandTestCase;
use Illuminate\Support\Facades\Artisan;

class CliInputConformanceTestCase extends FlagsCommandTestCase
{
    protected function configOverrides(): array
    {
        $vectors = featureFlagsCliInputVectors();

        return [
            ...parent::configOverrides(),
            'firefly.feature-flags.flags' => $vectors['flags'],
        ];
    }
}

uses(CliInputConformanceTestCase::class);

/**
 * @return array{flags: array<string, mixed>, action: string, key: string, cases: list<array{name: string, options: array<string, mixed>, expect: array{exit: int, error?: string, value?: mixed}}>}
 */
function featureFlagsCliInputVectors(): array
{
    $contents = file_get_contents(__DIR__.'/../Conformance/cli-input-vectors.json');
    if ($contents === false) {
        throw new RuntimeException('CLI input vectors could not be read');
    }

    /** @var array{flags: array<string, mixed>, action: string, key: string, cases: list<array{name: string, options: array<string, mixed>, expect: array{exit: int, error?: string, value?: mixed}}> } $vectors */
    $vectors = Json::members(Json::decode($contents));

    return $vectors;
}

$vectors = featureFlagsCliInputVectors();
$cases = [];
foreach ($vectors['cases'] as $case) {
    $cases[$case['name']] = [$case];
}

/** @param array<array-key, mixed> $case */
function featureFlagsRunCliInputVector(array $case): void
{
    $vectors = featureFlagsCliInputVectors();
    $options = $case['options'] ?? null;
    $expected = $case['expect'] ?? null;
    if (! Json::isObject($options) || ! is_array($expected) || ! is_int($expected['exit'] ?? null)) {
        throw new RuntimeException('Invalid CLI input vector');
    }

    $arguments = ['action' => $vectors['action'], 'key' => $vectors['key'], '--json' => true];
    foreach (Json::members($options) as $name => $value) {
        if (! is_string($name) || ! is_string($value)) {
            throw new RuntimeException('Invalid CLI input vector option');
        }
        $arguments['--'.$name] = $value;
    }

    $exit = Artisan::call('firefly:flags', $arguments);
    $body = Json::members(Json::decode(trim(Artisan::output())));

    expect($exit)->toBe($expected['exit']);
    if (isset($expected['error'])) {
        if (! is_string($expected['error'])) {
            throw new RuntimeException('Invalid CLI input vector error');
        }
        expect($body['error'])->toBe($expected['error'])
            ->and($body['message'])->toBeString()->not->toBeEmpty();
    }
    if (array_key_exists('value', $expected)) {
        expect($body['value'] ?? null)->toBe($expected['value']);
    }
}

it('consumes the CLI input vector through the Artisan command', function (array $case): void {
    featureFlagsRunCliInputVector($case);
})->with($cases);
