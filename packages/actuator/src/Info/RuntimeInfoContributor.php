<?php

declare(strict_types=1);

namespace Firefly\Actuator\Info;

use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Kernel\Version;
use Illuminate\Foundation\Application as LaravelApplication;

/**
 * Surfaces the process's own runtime facts under the `runtime` key, so /actuator/info says something on a
 * fresh application.
 *
 * WHY THIS EXISTS. The two contributors that shipped before it both read something the application had to
 * author first: AppInfoContributor renders `firefly.management.info.app.*` (absent until somebody writes it)
 * and BuildInfoContributor reads a generated firefly-build.json (absent until a release pipeline emits one).
 * On a skeleton neither fires, so /actuator/info answered `{}` — a 200 with nothing in it, which the admin
 * dashboard could only render as an apology telling the operator to go and configure something. That is the
 * wrong default for the endpoint an operator hits FIRST when they want to know what is actually running. This
 * contributor needs no configuration at all, because everything it publishes is already true of the process.
 *
 * WHAT IT PUBLISHES, AND WHY EACH FACT IS SAFE. PHP version, Laravel version, LaraFly version, SAPI, whether
 * OPcache is on, and current/peak memory. Every one of them is a property of the runtime rather than of the
 * application's data or configuration: none names a host, a credential, a path or a customer. They are also
 * the six facts that actually get asked for in an incident — "which PHP is that box on", "is OPcache even
 * enabled on the workers", "is this the release we think it is", "how close to the memory limit are we".
 * Deliberately NOT included: loaded extensions and ini settings (a fingerprint of exploitable versions),
 * anything from $_ENV or $_SERVER, and the application path.
 *
 * DEFAULT ON, WITH A SWITCH. `firefly.management.info.runtime.enabled` turns it off; matchIfMissing means an
 * application that has never heard of the key gets the data. Version numbers are a mild fingerprint, so an
 * application that publishes /actuator/info to the open internet may reasonably want it gone — and turning it
 * off REMOVES the bean (the condition is evaluated at boot, and InfoContributorRegistrar only ever sees
 * definitions that survived condition filtering), rather than registering a contributor that returns [].
 *
 * REGISTRATION. Nothing registers this explicitly: it is a #[Component] implementing InfoContributor, which
 * is exactly what InfoContributorRegistrar (BootPhase::WiringPasses, order 20) discovers and hands to
 * InfoContributorRegistry — the same path AppInfoContributor and BuildInfoContributor take. No #[Lazy]: it has
 * no constructor dependencies at all, so EagerSingletonsPass resolving it at phase 900 is free and harmless.
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.management.info.runtime.enabled', havingValue: 'true', matchIfMissing: true)]
final class RuntimeInfoContributor implements InfoContributor
{
    /**
     * @return array<string, mixed>
     */
    public function info(): array
    {
        return [
            'runtime' => [
                'php' => [
                    'version' => PHP_VERSION,
                    'sapi' => PHP_SAPI,
                    'opcache' => $this->opcacheEnabled(),
                ],
                'laravel' => [
                    'version' => $this->laravelVersion(),
                ],
                'firefly' => [
                    'version' => Version::VERSION,
                ],
                // memory_get_usage(true) rather than the emalloc figure: an operator watching a worker is
                // asking how much memory the PROCESS has taken from the OS and how close that came to
                // memory_limit, which is the real allocation, not the portion PHP's allocator currently has
                // handed out. Bytes, never a pre-formatted "12.4 MB" string — the endpoint is a JSON API, and
                // the dashboard's own Format helper is where humanising belongs.
                'memory' => [
                    'used' => memory_get_usage(true),
                    'peak' => memory_get_peak_usage(true),
                ],
            ],
        ];
    }

    /**
     * The Laravel version, or null on a host that has no illuminate/foundation.
     *
     * firefly/actuator's composer.json requires illuminate/container, /contracts, /database, /http, /log,
     * /routing and /support — NOT illuminate/foundation — and that omission is real, not an oversight: the
     * repo ships a Lumen sample, and Lumen has no Illuminate\Foundation\Application. Reading a class constant
     * off a missing class is a fatal Error (unlike an instanceof, which is merely false), so the guard is
     * load-bearing. FilterChainRegistrar in firefly/web sets the same precedent: reference the Foundation
     * class directly, guard its presence at runtime, and degrade instead of requiring the package.
     */
    private function laravelVersion(): ?string
    {
        return class_exists(LaravelApplication::class) ? LaravelApplication::VERSION : null;
    }

    /**
     * Whether OPcache is actually compiling, not merely installed.
     *
     * opcache_get_status() is the authority — `opcache.enable_cli` defaults to off, so an extension that is
     * loaded is routinely NOT caching anything under the CLI/queue-worker SAPI, and reporting "on" from
     * extension_loaded() alone would be actively misleading on exactly the processes an operator is trying to
     * diagnose. It can still be unavailable (the function does not exist without the extension) or refuse to
     * answer (opcache.restrict_api limits it to scripts under a configured path, and then it returns false
     * without throwing), so the ini setting is the documented fallback for that second case — the SAPI-correct
     * one, since enable_cli and enable are separate switches.
     */
    private function opcacheEnabled(): bool
    {
        if (! function_exists('opcache_get_status')) {
            return false;
        }

        $status = @opcache_get_status(false);
        if (is_array($status)) {
            $enabled = $status['opcache_enabled'] ?? false;

            return $enabled === true;
        }

        return filter_var(ini_get(PHP_SAPI === 'cli' ? 'opcache.enable_cli' : 'opcache.enable'), FILTER_VALIDATE_BOOLEAN);
    }
}
