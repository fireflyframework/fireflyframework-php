<?php

declare(strict_types=1);

use Firefly\Observability\Logging\FireflyContextLogProcessor;
use Firefly\Observability\Tests\Support\BuiltChannelDisabledCapstoneTestCase;
use Firefly\Web\Filter\CorrelationIdFilter;
use Illuminate\Contracts\Log\ContextLogProcessor as ContextLogProcessorContract;
use Illuminate\Log\Context\ContextLogProcessor as LaravelContextLogProcessor;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;

uses(BuiltChannelDisabledCapstoneTestCase::class);

/**
 * `firefly.logging.structured.all-channels` is a real gate, not a comment: with it off, the contract keeps
 * Laravel's own binding and a channel built after boot is byte-for-byte what it was before this feature.
 */
it('leaves Laravel\'s own ContextLogProcessor binding alone when the gate is off', function () {
    /** @var BuiltChannelDisabledCapstoneTestCase $this */
    $processor = $this->app()->make(ContextLogProcessorContract::class);

    expect($processor)->toBeInstanceOf(LaravelContextLogProcessor::class)
        ->and($processor)->not->toBeInstanceOf(FireflyContextLogProcessor::class);
});

it('writes no normalised ids onto a channel built after boot when the gate is off', function () {
    /** @var BuiltChannelDisabledCapstoneTestCase $this */
    $path = sys_get_temp_dir().'/firefly-built-channel-off-'.bin2hex(random_bytes(4)).'.log';

    $this->app()->make(ContextRepository::class);

    Context::add(CorrelationIdFilter::CONTEXT_KEY, 'corr-off');

    try {
        Log::build(['driver' => 'single', 'path' => $path])->info('after boot');

        expect(file_get_contents($path))->not->toContain('"correlation_id":"corr-off"');
    } finally {
        @unlink($path);
    }
});
