<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Web;

use Composer\InstalledVersions;

/**
 * Locates and serves the OFFICIAL Swagger UI distribution from the `swagger-api/swagger-ui` composer package.
 *
 * WHY NOT A CDN. An internal API console that phones out to a third-party host on every page view is a
 * supply-chain dependency and a data-protection question, and it renders nothing at all in the air-gapped and
 * locked-down-CSP environments where an internal console is most wanted. Serving the very same official
 * assets from the application's own origin removes both problems and keeps the UI byte-for-byte the one
 * Swagger publishes.
 *
 * WHY NOT VENDOR THE FILES INTO THIS REPOSITORY. `swagger-api/swagger-ui` already publishes the dist on
 * Packagist under Apache-2.0, so composer can fetch and pin it. Copying 4 MB of minified JavaScript into a
 * PHP package's git history would make every clone pay for it and would pin the framework to a release train
 * it could not patch without a new release of its own.
 *
 * PATH TRAVERSAL. Only the basenames in ALLOWED are servable, and each resolved path is checked with
 * realpath() to be inside the dist directory. A request is matched against a fixed list rather than
 * sanitised, because a whitelist cannot be defeated by an encoding trick that a sanitiser missed.
 */
final class SwaggerAssets
{
    /**
     * The files the page actually references, plus the OAuth2 redirect Swagger UI opens in a popup. Anything
     * else in the dist — source maps, the ES bundles, the stock index.html with its own initializer — is not
     * served, because nothing here links to it.
     *
     * @var array<string, string> basename => content type
     */
    private const ALLOWED = [
        'swagger-ui.css' => 'text/css; charset=UTF-8',
        'swagger-ui-bundle.js' => 'application/javascript; charset=UTF-8',
        'swagger-ui-standalone-preset.js' => 'application/javascript; charset=UTF-8',
        'oauth2-redirect.html' => 'text/html; charset=UTF-8',
        'favicon-16x16.png' => 'image/png',
        'favicon-32x32.png' => 'image/png',
        'index.css' => 'text/css; charset=UTF-8',
    ];

    public function __construct(private readonly ?string $distPath = null) {}

    /** True when the official distribution is installed and readable. */
    public function available(): bool
    {
        return $this->dist() !== null;
    }

    /** @return list<string> */
    public function servable(): array
    {
        return array_keys(self::ALLOWED);
    }

    public function contentType(string $file): ?string
    {
        return self::ALLOWED[$file] ?? null;
    }

    /**
     * The absolute path of one servable asset, or null when the name is not on the whitelist, the
     * distribution is absent, or the resolved file escapes the dist directory.
     */
    public function path(string $file): ?string
    {
        $dist = $this->dist();
        if ($dist === null || ! isset(self::ALLOWED[$file])) {
            return null;
        }

        $resolved = realpath($dist.'/'.$file);

        return $resolved !== false && str_starts_with($resolved, $dist.DIRECTORY_SEPARATOR) && is_file($resolved)
            ? $resolved
            : null;
    }

    /**
     * The dist directory, resolved once.
     *
     * Composer's own autoloader is asked for the package root rather than a path being guessed from __DIR__,
     * because the depth from this file to vendor/ differs between an installed package
     * (vendor/firefly/openapi/src/Web) and this monorepo (packages/openapi/src/Web) — a relative walk would
     * work in exactly one of them.
     */
    private function dist(): ?string
    {
        $candidates = $this->distPath !== null ? [$this->distPath] : $this->discover();

        foreach ($candidates as $candidate) {
            $resolved = realpath($candidate);
            if ($resolved !== false && is_dir($resolved) && is_file($resolved.'/swagger-ui-bundle.js')) {
                return $resolved;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function discover(): array
    {
        $paths = [];

        if (class_exists(InstalledVersions::class)) {
            try {
                $root = InstalledVersions::getInstallPath('swagger-api/swagger-ui');
                if (is_string($root)) {
                    $paths[] = $root.'/dist';
                }
            } catch (\Throwable) {
                // Not installed, or an installed.php too old to answer — fall through to the vendor walk.
            }
        }

        // A plain walk upwards, for an autoloader that cannot answer (a phar, a non-composer runtime).
        for ($up = 2; $up <= 6; $up++) {
            $paths[] = dirname(__DIR__, $up).'/vendor/swagger-api/swagger-ui/dist';
        }

        return $paths;
    }
}
