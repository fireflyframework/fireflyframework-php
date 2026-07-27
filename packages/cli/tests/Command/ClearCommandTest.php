<?php

declare(strict_types=1);

use Firefly\Cli\Cache\FireflyCachePaths;
use Firefly\Cli\Tests\Command\ClearCommandTestCase;
use Firefly\Cli\Tests\Support\ArtisanAssertions;
use Illuminate\Filesystem\Filesystem;

// NOTE: the brief's literal test used `uses(new class extends FireflyTestCase { ... }::class)`.
// That instantiates the anonymous class immediately to read its ::class, which throws before Pest
// ever binds it — PHPUnit\Framework\TestCase::__construct() requires a `string $name` argument that
// a bare `new` expression never supplies (verified: ArgumentCountError). Following the monorepo
// convention (see packages/testing/tests/Support/ProbeFireflyTestCase.php) with a NAMED support class.
uses(ClearCommandTestCase::class);

it('removes the app cache dir', function () {
    /** @var ClearCommandTestCase $this */
    $dir = FireflyCachePaths::dir($this->app());
    @mkdir($dir.'/'.FireflyCachePaths::PROXY_DIR, 0o755, true);
    file_put_contents($dir.'/'.FireflyCachePaths::ROUTES, "<?php return [];\n");

    ArtisanAssertions::exitCode($this->artisan('firefly:clear'), 0);

    expect(is_dir($dir))->toBeFalse();
});

it('is idempotent — exits 0 when the cache dir is already absent', function () {
    /** @var ClearCommandTestCase $this */
    $dir = FireflyCachePaths::dir($this->app());

    // Defensive: guarantee the "nothing cached yet" precondition regardless of test order/PID reuse.
    if (is_dir($dir)) {
        (new Filesystem)->deleteDirectory($dir);
    }
    expect(is_dir($dir))->toBeFalse();

    ArtisanAssertions::exitCode($this->artisan('firefly:clear'), 0);

    expect(is_dir($dir))->toBeFalse();
});
