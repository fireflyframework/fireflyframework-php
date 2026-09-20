<?php

declare(strict_types=1);

use Firefly\Observability\Tracing\SpanContext;

it('generates a valid W3C-shaped context with a 32-hex trace id and a 16-hex span id', function () {
    $context = SpanContext::generate();

    expect($context->isValid())->toBeTrue()
        ->and($context->traceId)->toMatch('/^[0-9a-f]{32}$/')
        ->and($context->spanId)->toMatch('/^[0-9a-f]{16}$/')
        ->and($context->sampled)->toBeTrue()
        ->and($context->traceFlags())->toBe('01')
        ->and($context->remote)->toBeFalse();
});

it('keeps the trace id it is given and mints only the span id', function () {
    $context = SpanContext::generate('4bf92f3577b34da6a3ce929d0e0e4736', sampled: false, traceState: 'congo=t61rcWkgMzE');

    expect($context->traceId)->toBe('4bf92f3577b34da6a3ce929d0e0e4736')
        ->and($context->spanId)->not->toBe('00f067aa0ba902b7')
        ->and($context->traceFlags())->toBe('00')
        ->and($context->traceState)->toBe('congo=t61rcWkgMzE');
});

it('treats the all-zero ids, and anything that is not lowercase hex of the right length, as invalid', function () {
    expect(SpanContext::invalid()->isValid())->toBeFalse()
        ->and((new SpanContext('4bf92f3577b34da6a3ce929d0e0e4736', '0000000000000000'))->isValid())->toBeFalse()
        ->and((new SpanContext('00000000000000000000000000000000', '00f067aa0ba902b7'))->isValid())->toBeFalse()
        ->and((new SpanContext('4BF92F3577B34DA6A3CE929D0E0E4736', '00f067aa0ba902b7'))->isValid())->toBeFalse()
        ->and((new SpanContext('4bf92f3577b34da6', '00f067aa0ba902b7'))->isValid())->toBeFalse();
});
