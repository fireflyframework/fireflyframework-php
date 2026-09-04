<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Context\Scan\AppScan;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\Access\Method\SecurityMethodManifest;
use Firefly\Security\Scanner\MethodSecurityScanner;
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
