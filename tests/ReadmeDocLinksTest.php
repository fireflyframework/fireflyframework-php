<?php

declare(strict_types=1);

it('resolves every relative markdown link in the README', function () {
    $readme = (string) file_get_contents(__DIR__.'/../README.md');
    preg_match_all('/\]\((?!https?:|#)([^)]+)\)/', $readme, $m);
    $missing = [];
    foreach ($m[1] as $target) {
        $path = __DIR__.'/../'.strtok($target, '#');
        if (! file_exists($path)) {
            $missing[] = $target;
        }
    }
    expect($missing)->toBe([]);
});
