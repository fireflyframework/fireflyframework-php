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

/*
 * SPRING'S DURATION FORMAT IS ACCEPTED TOO. `#[Scheduled]` claims @Scheduled parity, and Spring reads
 * `fixedDelayString`, `initialDelayString` and lock durations as ISO-8601 (`PT14M`), the java.time.Duration
 * form. Days, hours, minutes and fractional seconds are accepted case-insensitively; years, months and weeks
 * are refused because their length in seconds is not fixed, as java.time.Duration refuses them.
 */
it('parses an ISO-8601 duration to seconds', function (string $input, float $seconds) {
    expect(Duration::parse($input))->toBe($seconds);
})->with([
    'minutes' => ['PT14M', 840.0],
    'hours' => ['PT1H', 3600.0],
    'seconds' => ['PT30S', 30.0],
    'fractional seconds' => ['PT0.25S', 0.25],
    'hours and minutes' => ['PT1H30M', 5400.0],
    'days' => ['P1D', 86400.0],
    'days and hours' => ['P1DT2H', 93600.0],
    'lower case' => ['pt5m', 300.0],
    'whitespace tolerated' => ['  PT4M ', 240.0],
]);

it('rejects an ISO-8601 duration without a fixed length or a component', function (string $input) {
    expect(fn () => Duration::parse($input))->toThrow(ConfigurationException::class);
})->with([
    'no component' => ['P'],
    'time marker only' => ['PT'],
    'years' => ['P1Y'],
    'months' => ['P1M'],
    'weeks' => ['P1W'],
    'negative' => ['-PT5M'],
    'unit missing' => ['PT5'],
    'minutes before hours' => ['PT5M1H'],
]);
