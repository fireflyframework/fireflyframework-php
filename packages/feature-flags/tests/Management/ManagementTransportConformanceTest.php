<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\Tests\Support\ManagementEndpointTestCase;

class ManagementTransportConformanceTestCase extends ManagementEndpointTestCase
{
    protected function configOverrides(): array
    {
        /** @var array{flags: array<string, mixed>, cases: list<array{name: string, config?: array{writes?: bool, store?: bool}}>} $vectors */
        $vectors = Json::members(Json::decode((string) file_get_contents(__DIR__.'/../Conformance/management-transport-vectors.json')));
        foreach ($vectors['cases'] as $case) {
            if ('dataset "'.$case['name'].'"' === $this->dataName()) {
                $options = isset($case['config']) ? Json::members($case['config']) : [];

                return [
                    ...parent::configOverrides(),
                    'firefly.feature-flags.flags' => $vectors['flags'],
                    'firefly.feature-flags.management.writes' => $options['writes'] ?? true,
                    'firefly.feature-flags.sources.store.enabled' => $options['store'] ?? true,
                ];
            }
        }

        throw new RuntimeException('Unknown management transport vector: '.$this->dataName());
    }
}

uses(ManagementTransportConformanceTestCase::class);

$contents = file_get_contents(__DIR__.'/../Conformance/management-transport-vectors.json');
if ($contents === false) {
    throw new RuntimeException('management transport vectors could not be read');
}
/** @var array{cases: list<array{name: string, method: string, path: string, headers?: array<string, string>, rawBody?: string, body?: array<string, mixed>, expect: array{error?: string, actor?: string, status: array{larafly: int}}}>} $vectors */
$vectors = Json::members(Json::decode($contents));
$cases = [];
foreach ($vectors['cases'] as $case) {
    $cases[$case['name']] = [$case];
}

/** @param array<array-key, mixed> $case */
function featureFlagsManagementTransportRequest(ManagementTransportConformanceTestCase $test, array $case): void
{
    $method = $case['method'] ?? null;
    $path = $case['path'] ?? null;
    $expected = $case['expect'] ?? null;
    if (! is_string($method) || ! is_string($path) || ! is_array($expected)) {
        throw new RuntimeException('Invalid management transport request vector');
    }
    $statuses = $expected['status'] ?? null;
    $status = is_array($statuses) ? ($statuses['larafly'] ?? null) : null;
    if (! is_int($status)) {
        throw new RuntimeException('Invalid management transport response vector');
    }
    $headers = ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'];
    $extraHeaders = $case['headers'] ?? [];
    if (! is_array($extraHeaders)) {
        throw new RuntimeException('Invalid management transport headers');
    }
    foreach ($extraHeaders as $name => $value) {
        if (! is_string($name) || ! is_string($value)) {
            throw new RuntimeException('Invalid management transport header');
        }
        $headers['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
    }
    $body = $case['rawBody'] ?? (isset($case['body']) ? Json::encode($case['body']) : null);
    if ($body !== null && ! is_string($body)) {
        throw new RuntimeException('Invalid management transport body');
    }
    $response = $test->call($method, $path, [], [], [], $headers, $body);
    $response->assertStatus($status);
    $decoded = Json::members(Json::decode((string) $response->getContent()));
    if (isset($expected['error'])) {
        expect($decoded['error'])->toBe($expected['error'])
            ->and($decoded['message'])->toBeString()->not->toBeEmpty();
    }
    if (isset($expected['actor'])) {
        $test->getJson('/actuator/flags/kill')->assertOk()->assertJsonPath('history.0.actor', $expected['actor']);
    }
}

it('consumes the management transport vector through the HTTP route', function (array $case): void {
    /** @var ManagementTransportConformanceTestCase $this */
    featureFlagsManagementTransportRequest($this, $case);
})->with($cases);
