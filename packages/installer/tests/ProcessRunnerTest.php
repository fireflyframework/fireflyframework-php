<?php

declare(strict_types=1);

use Firefly\Installer\Tests\Support\FakeProcessRunner;

it('records each invocation and returns the configured exit code', function () {
    $runner = new FakeProcessRunner(exitCode: 0);

    $code = $runner->run(['composer', '--version'], '/tmp/app');

    expect($code)->toBe(0)
        ->and($runner->calls)->toHaveCount(1)
        ->and($runner->calls[0]['command'])->toBe(['composer', '--version'])
        ->and($runner->calls[0]['cwd'])->toBe('/tmp/app');
});

it('surfaces a non-zero exit code unchanged', function () {
    $runner = new FakeProcessRunner(exitCode: 7);

    expect($runner->run(['git', 'init']))->toBe(7);
});
