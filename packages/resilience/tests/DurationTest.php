<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Resilience\Duration;

it('parses each unit suffix to seconds', function (string $input, float $seconds) {
    expect(Duration::parse($input))->toBe($seconds);
})->with([
    'milliseconds' => ['250ms', 0.25],
    'seconds' => ['30s', 30.0],
    'minutes' => ['5m', 300.0],
    'hours' => ['1h', 3600.0],
    'bare number is seconds' => ['5', 5.0],
    'float seconds' => ['1.5s', 1.5],
    'float bare' => ['0.5', 0.5],
    'whitespace tolerated' => ['  2 s  ', 2.0],
]);

it('rejects an unparseable duration', function (string $input) {
    expect(fn () => Duration::parse($input))->toThrow(ConfigurationException::class);
})->with(['empty' => [''], 'letters' => ['abc'], 'bad unit' => ['5d'], 'negative' => ['-3s']]);
