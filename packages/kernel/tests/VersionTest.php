<?php

declare(strict_types=1);

use Firefly\Kernel\Version;

it('is a valid CalVer YY.MM.Patch string', function () {
    expect(Version::VERSION)->toMatch('/^\d{2}\.\d{2}\.\d+$/');
});
