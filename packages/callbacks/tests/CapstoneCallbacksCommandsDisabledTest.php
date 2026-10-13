<?php

declare(strict_types=1);

use Firefly\Callbacks\Tests\Support\CallbacksArtisan;
use Firefly\Callbacks\Tests\Support\CallbacksDisabledCapstoneTestCase;

uses(CallbacksDisabledCapstoneTestCase::class);

it('says callbacks are off instead of listing or replaying nothing', function () {
    /** @var CallbacksDisabledCapstoneTestCase $this */
    CallbacksArtisan::pending($this->artisan('firefly:callbacks:list'))->expectsOutputToContain('firefly.callbacks.enabled is false')->assertExitCode(1);
    CallbacksArtisan::pending($this->artisan('firefly:callbacks:replay', ['--failed' => true]))->expectsOutputToContain('firefly.callbacks.enabled is false')->assertExitCode(1);
});
