<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\FeatureFlags\Definition\Json;
use Firefly\FeatureFlags\FeatureFlagsSettings;
use Firefly\FeatureFlags\Source\FlagSourceUnavailable;
use Firefly\FeatureFlags\Source\HttpFlagSource;
use Firefly\FeatureFlags\Tests\Support\ConformanceFiles;
use Firefly\FeatureFlags\Tests\Support\SyncServerTestCase;
use Illuminate\Config\Repository;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;

uses(SyncServerTestCase::class);

/** @var array{cases: list<array<string, mixed>>} $httpVectors */
$httpVectors = json_decode((string) file_get_contents(ConformanceFiles::root().'/http-vectors.json'), true, 512, JSON_THROW_ON_ERROR);
$serverCases = [];
$clientCases = [];
foreach ($httpVectors['cases'] as $case) {
    $name = $case['name'];
    if (! is_string($name)) {
        throw new UnexpectedValueException('HTTP vectors require named cases.');
    }
    if ($case['kind'] === 'server') {
        $serverCases[$name] = [$case];
    } else {
        $clientCases[$name] = [$case];
    }
}

it('implements shared sync conditions', function (array $case): void {
    /** @var SyncServerTestCase $this */
    $condition = $case['condition'];
    $expected = Json::members($case['expect']);
    $status = $expected['status'];
    if (($condition !== null && ! is_string($condition)) || ! is_int($status)) {
        throw new UnexpectedValueException('Server vectors require a condition and status.');
    }
    $first = $this->get('/feature-flags/flagd.json', ['Authorization' => 'Bearer s3cret']);
    $etag = (string) $first->headers->get('ETag');
    $headers = [];
    if ($case['authorization'] !== 'missing') {
        $headers['Authorization'] = 'Bearer '.($case['authorization'] === 'valid' ? 's3cret' : 'wrong');
    }
    if ($condition !== null) {
        $headers['If-None-Match'] = str_replace('{etag}', $etag, $condition);
    }
    $response = $this->get('/feature-flags/flagd.json', $headers);
    $response->assertStatus($status);
    if ($response->status() === 200) {
        expect($response->getContent())->toBe($first->getContent());
    } elseif ($response->status() === 304) {
        expect($response->getContent())->toBe('')->and($response->headers->get('ETag'))->toBe($etag);
    } else {
        expect($response->headers->has('ETag'))->toBeFalse();
    }
})->with($serverCases);

it('implements shared poll conditions', function (array $case): void {
    $firstEtag = $case['firstEtag'];
    $body = $case['body'];
    $expectedResult = Json::members($case['expect']);
    if (($firstEtag !== null && ! is_string($firstEtag)) || ! is_string($body)) {
        throw new UnexpectedValueException('Client vectors require body text and an optional ETag.');
    }
    $http = new Factory;
    $requests = [];
    $http->fake(function (Request $request) use ($firstEtag, $body, &$requests) {
        $requests[] = $request;
        if ($firstEtag === null || count($requests) > 1) {
            return Factory::response('', 304);
        }

        return Factory::response($body, 200, $firstEtag === '' ? [] : ['ETag' => $firstEtag]);
    });
    $settings = FeatureFlagsSettings::fromConfig(new Config(new Repository(['firefly' => ['feature-flags' => ['sources' => ['http' => ['url' => 'http://test/flags', 'token' => 'proof']]]]])));
    $source = new HttpFlagSource($settings, $http);
    if ($expectedResult['error']) {
        expect(fn () => $source->load(null))->toThrow(FlagSourceUnavailable::class, 'HTTP 304');
    } else {
        $snapshot = $source->load(null);
        $expected = $firstEtag ?: '"'.hash('sha256', $body).'"';
        expect($snapshot?->revision)->toBe($expected)
            ->and($snapshot?->document->flag('a')?->defaultVariant())->toBe('on')
            ->and($source->load($expected))->toBeNull()
            ->and($requests[1]->header('If-None-Match'))->toBe([$expected]);
    }
    expect($requests[0]->hasHeader('If-None-Match'))->toBeFalse()
        ->and($requests[0]->header('Authorization'))->toBe(['Bearer proof']);
})->with($clientCases);
