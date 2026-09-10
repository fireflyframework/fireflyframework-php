<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Context\Scan\AppScan;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\Access\Method\SecurityMethodManifest;
use Firefly\Security\Scanner\MethodSecurityScanner;
use Firefly\Security\SecurityWiringProvider;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;

/**
 * The fail-OPEN regression guard.
 *
 * SecurityWiringProvider used to bind an unconditional `new SecurityMethodManifest([])`. Because both
 * enforcement sites (MethodSecurityControllerGuard, MethodSecurityMessageEnforcer) treat "no rule for this
 * method" as ALLOW — method security is additive, not a second deny-by-default gate — an empty manifest
 * silently disabled every #[PreAuthorize] in the app. The only thing that ever bound the real rules was
 * firefly/cli, a require-dev package that was absent from the firefly/firefly metapackage.
 *
 * These assert the two properties that make the fix real: the uncached path finds rules, and strict mode
 * turns a missing compiled manifest into a boot failure instead of an unguarded application.
 */
/** @return array<string,string> */
function securityScanPaths(): array
{
    return ['Firefly\\Security\\Tests\\Fixtures\\' => dirname(__DIR__).'/Fixtures'];
}

it('finds method-security rules by scanning in-process when nothing compiled a manifest', function () {
    $rules = (new MethodSecurityScanner)->scan(securityScanPaths());

    expect($rules)->not->toBeEmpty();

    // The manifest the provider builds on the uncached path must actually answer ruleFor() — an empty one
    // is what made every #[PreAuthorize] a silent no-op.
    $manifest = new SecurityMethodManifest($rules);
    $first = $rules[0];

    expect($manifest->ruleFor($first->class, $first->method))->not->toBeNull();
});

it('is configured to fail closed: strict mode refuses to boot without a compiled manifest', function () {
    $app = new Container;
    $repository = new Repository([
        'firefly' => [
            'cache' => ['path' => sys_get_temp_dir().'/firefly-definitely-not-here-'.bin2hex(random_bytes(6))],
            'security' => ['method' => ['strict' => true]],
        ],
    ]);
    $app->instance('config', $repository);

    // Mirrors the provider's binding: no artifact + strict => ConfigurationException rather than an
    // empty (and therefore permissive) manifest.
    $resolve = static function (Container $app): SecurityMethodManifest {
        $file = AppScan::cachedFile($app, AppScan::SECURITY_METHODS);
        /** @var Repository $repository */
        $repository = $app->get('config');
        $strict = (new Config($repository))->bool('firefly.security.method.strict', false);

        if ($file !== null) {
            return SecurityMethodManifest::load($file);
        }
        if ($strict) {
            throw new ConfigurationException('no compiled method-security manifest');
        }

        return new SecurityMethodManifest([]);
    };

    expect(static fn () => $resolve($app))->toThrow(ConfigurationException::class);
});

/*
 | …but it must not fail closed against the command that produces the manifest.
 |
 | `firefly:cache` is the only thing that writes security-methods.php, and it can only run by booting the
 | application. With strict mode on and no manifest yet — a fresh clone, a cleared cache directory, or the
 | very first build of an image — the boot refused, so the command could never write the file it was being
 | told to run. The instruction in the error message ("Run `php artisan firefly:cache`") was unfollowable.
 |
 | While regenerating, the provider therefore takes the same in-process scan the non-strict path takes. It
 | is not a hole: the process is a developer's or an image build's own `firefly:cache` invocation, it
 | serves no request, and the manifest it writes is what every later boot enforces strictly.
 */
it('lets the boot that regenerates the manifest through, even under strict mode', function () {
    $app = new Container;
    $app->instance('config', new Repository([
        'firefly' => [
            'cache' => ['path' => sys_get_temp_dir().'/firefly-definitely-not-here-'.bin2hex(random_bytes(6))],
            'security' => ['method' => ['strict' => true]],
            'scan' => ['paths' => securityScanPaths()],
        ],
    ]));

    $original = $_SERVER['argv'] ?? [];
    $_SERVER['argv'] = ['artisan', 'firefly:cache'];

    try {
        $manifest = SecurityWiringProvider::methodManifest($app);

        expect($manifest)->toBeInstanceOf(SecurityMethodManifest::class);

        // and it is the REAL scan, not an empty (permissive) stand-in
        $rules = (new MethodSecurityScanner)->scan(securityScanPaths());
        $first = $rules[0];
        expect($manifest->ruleFor($first->class, $first->method))->not->toBeNull();
    } finally {
        $_SERVER['argv'] = $original;
    }
});

it('still refuses a normal boot under strict mode with no manifest', function () {
    $app = new Container;
    $app->instance('config', new Repository([
        'firefly' => [
            'cache' => ['path' => sys_get_temp_dir().'/firefly-definitely-not-here-'.bin2hex(random_bytes(6))],
            'security' => ['method' => ['strict' => true]],
            'scan' => ['paths' => securityScanPaths()],
        ],
    ]));

    $original = $_SERVER['argv'] ?? [];
    $_SERVER['argv'] = ['artisan', 'serve'];

    try {
        expect(fn () => SecurityWiringProvider::methodManifest($app))
            ->toThrow(ConfigurationException::class);
    } finally {
        $_SERVER['argv'] = $original;
    }
});
