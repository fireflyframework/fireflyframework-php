<?php

declare(strict_types=1);

use Firefly\Testing\Testing;

it('autoloads the firefly/testing package', function () {
    expect(Testing::PACKAGE)->toBe('firefly/testing');
});
