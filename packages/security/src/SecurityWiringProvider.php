<?php

declare(strict_types=1);

namespace Firefly\Security;

use Firefly\Config\Config;
use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Context\Scan\AppScan;
use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\Access\Method\SecurityMethodManifest;
use Firefly\Security\Boot\SecurityWiringPass;
use Firefly\Security\Scanner\MethodSecurityScanner;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;

/**
 * The boot-pass half of firefly/security (cannot ride on SecurityServiceProvider — AutoConfiguration's final
 * register() records candidacy only). Resolves the SecurityMethodManifest behind a bound() guard and contributes
 * the SecurityWiringPass. Both this and SecurityServiceProvider are in extra.laravel.providers.
 *
 * FAIL-OPEN FIX. This binding used to be an unconditional `new SecurityMethodManifest([])`. Because
 * MethodSecurityMessageEnforcer::enforce() and MethodSecurityControllerGuard treat "no rule for this method" as
 * ALLOW — method security is additive, not a second deny-by-default gate — an empty manifest silently disabled
 * every #[PreAuthorize], #[PostAuthorize], #[Secured] and #[RolesAllowed] in the application. Nothing logged it
 * and no test caught it, because only firefly/cli's FireflyCacheServiceProvider ever bound the compiled rules
 * and firefly/cli is a require-dev package absent from the firefly/firefly metapackage.
 *
 * Resolution order is now the same as every other Category-B manifest — compiled artifact, then an in-process
 * scan of firefly.scan.paths, then empty — so an uncached app enforces the same rules a cached one does.
 *
 * `firefly.security.method.strict` (default false) additionally refuses to boot when no compiled artifact is
 * present. Set it in production: it converts "someone forgot to run firefly:cache" from silently unguarded
 * handlers into a startup failure, and it is the only defence against a build that ships without the manifest.
 */
final class SecurityWiringProvider extends FireflyServiceProvider
{
    public function register(): void
    {
        if (! $this->app->bound(SecurityMethodManifest::class)) {
            $this->app->singleton(SecurityMethodManifest::class, static function (Container $app): SecurityMethodManifest {
                $file = AppScan::cachedFile($app, AppScan::SECURITY_METHODS);

                /** @var Repository $repository */
                $repository = $app->get('config');
                $strict = (new Config($repository))->bool('firefly.security.method.strict', false);

                if ($file !== null) {
                    return SecurityMethodManifest::load($file);
                }

                if ($strict) {
                    throw new ConfigurationException(
                        'Refusing to boot: firefly.security.method.strict is enabled but no compiled method-security '
                        .'manifest was found at '.AppScan::dir($app).'/'.AppScan::SECURITY_METHODS.'. Run `php artisan '
                        .'firefly:cache`, or disable strict mode to allow the in-process scan fallback.'
                    );
                }

                $paths = AppScan::paths($app);

                return new SecurityMethodManifest($paths === [] ? [] : (new MethodSecurityScanner)->scan($paths));
            });
        }

        parent::register();
    }

    /**
     * @return list<BootPass>
     */
    public function passes(): array
    {
        return [new SecurityWiringPass];
    }
}
