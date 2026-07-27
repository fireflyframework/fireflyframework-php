<?php

declare(strict_types=1);

use Firefly\Cli\Cli;

it('autoloads the firefly/cli package', function () {
    expect(Cli::PACKAGE)->toBe('firefly/cli');
});
