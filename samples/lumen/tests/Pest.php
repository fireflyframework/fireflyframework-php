<?php

declare(strict_types=1);

use Lumen\Tests\LumenTestCase;

// Intent: bind every test under samples/lumen/tests to the LumenTestCase boot harness.
//
// NOTE for S2-S7 authors: in this monorepo layout Pest loads only the root tests/Pest.php, so this nested
// uses()->in() does NOT auto-apply. Follow the repository convention used by every package capstone test —
// declare `uses(LumenTestCase::class);` at the top of each test file (see SmokeBootTest.php). This directive is
// kept as the canonical, forward-compatible hook should the suite ever be reorganized to discover it.
uses(LumenTestCase::class)->in(__DIR__);
