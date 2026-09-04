<?php

declare(strict_types=1);

namespace Firefly\Installer;

/**
 * Shapes the directory `composer create-project firefly/skeleton` produced into the requested archetype:
 * rewrites the generated composer.json and prunes/adds the files that differ.
 *
 * Everything here runs AFTER create-project, against a directory on disk, which is what makes it testable
 * without a network: ArchetypeTest copies the monorepo's real skeleton/ into a temp directory and asserts
 * the exact composer.json and the exact file set that comes out.
 *
 * "PRE-WIRED" MEANS "REQUIRED", NOT "CONFIGURED"
 * ----------------------------------------------
 * A capability's only footprint is a line in the require block. That is not laziness — it is the whole
 * point of conditional auto-configuration: firefly/security, firefly/eda and firefly/scheduling each carry
 * their own #[AutoConfiguration] guarded by #[ConditionalOnClass]/#[ConditionalOnMissingBean], so being
 * INSTALLED is being wired. An installer that also wrote config/security.php would be writing a second,
 * staler copy of defaults the package already owns, and the user would have to keep it in sync forever.
 */
final class ArchetypeApplier
{
    /** @param list<Capability> $capabilities */
    public function __construct(
        private readonly Archetype $archetype,
        private readonly array $capabilities,
    ) {}

    /**
     * @return list<string> one human-readable line per change, for the command to echo
     */
    public function applyTo(string $directory): array
    {
        return [...$this->rewriteManifest($directory), ...$this->shapeFiles($directory)];
    }

    /**
     * @return list<string>
     */
    private function rewriteManifest(string $directory): array
    {
        $path = $directory.'/composer.json';
        if (! is_file($path)) {
            // Not an error: ProcessRunner is a seam, and under a fake runner nothing was ever generated.
            // A create-project that genuinely failed has already returned non-zero and never reached here.
            return [];
        }

        $raw = file_get_contents($path);
        $decoded = $raw === false ? null : json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }
        /** @var array<string, mixed> $manifest */
        $manifest = $decoded;

        $require = $this->stringMap($manifest['require'] ?? null);
        $requireDev = $this->stringMap($manifest['require-dev'] ?? null);
        $constraint = $this->fireflyConstraint($require);

        $notes = [];
        foreach ($this->capabilities as $capability) {
            $target = $capability->dev ? 'require-dev' : 'require';
            $existing = $capability->dev ? $requireDev : $require;
            if (isset($existing[$capability->package])) {
                continue; // already a direct dependency — the skeleton's own, or a duplicate --with
            }
            if ($capability->dev) {
                $requireDev[$capability->package] = $constraint;
            } else {
                $require[$capability->package] = $constraint;
            }
            $notes[] = sprintf('composer.json: + %s (%s)', $capability->package, $target);
        }

        $manifest['require'] = $this->sortPackages($require);
        if ($requireDev !== []) {
            $manifest['require-dev'] = $this->sortPackages($requireDev);
        }

        /** @var array<string, mixed> $extra */
        $extra = is_array($manifest['extra'] ?? null) ? $manifest['extra'] : [];
        $extra['firefly'] = [
            'archetype' => $this->archetype->value,
            'capabilities' => array_map(static fn (Capability $c): string => $c->id, $this->capabilities),
        ];
        $manifest['extra'] = $extra;

        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return $notes;
        }
        file_put_contents($path, $json."\n");

        return $notes;
    }

    /**
     * @return list<string>
     */
    private function shapeFiles(string $directory): array
    {
        $notes = [];

        foreach ($this->archetype->prunes() as $relative) {
            $path = $directory.'/'.$relative;
            if (! file_exists($path) && ! is_link($path)) {
                continue;
            }
            if (Filesystem::delete($path)) {
                Filesystem::pruneEmptyDirectories($directory, dirname($path));
                $notes[] = 'removed '.$relative;
            }
        }

        $prerequisites = $this->archetype->stubPrerequisites();
        foreach ($this->archetype->stubs() as $relative => $source) {
            $needs = $prerequisites[$relative] ?? null;
            if ($needs !== null && ! is_file($directory.'/'.$needs)) {
                // The generated project does not carry what this stub extends (see
                // Archetype::stubPrerequisites()); writing it anyway would fatal the user's first
                // `composer test` rather than green it.
                $notes[] = sprintf('skipped %s (this project ships no %s)', $relative, $needs);

                continue;
            }
            if (is_file($source) && Filesystem::copy($source, $directory.'/'.$relative)) {
                $notes[] = 'added   '.$relative;
            }
        }

        return [...$notes, ...$this->invalidateCompiledManifests($directory)];
    }

    /**
     * Drop the compiled manifests `composer create-project` already wrote.
     *
     * THE BUG THIS FIXES: the skeleton's post-create-project-cmd ends in `php artisan firefly:cache`, so by
     * the time the archetype prunes app/Http/WelcomeController.php the compiled routes.php and
     * component.php ALREADY name it. Verified end-to-end against a real create-project: a generated `--api`
     * project answered `GET /` with a 500 — "Target class [App\Http\WelcomeController] does not exist" —
     * because the compiled manifest outlived the class it pointed at, where the same project with no
     * manifests correctly 404s.
     *
     * Deleting is the half of the fix that cannot fail: an app with no compiled manifests boots by
     * SCANNING, which is slower but always correct. NewCommand re-runs firefly:cache afterwards to put the
     * compiled path back, and if that ever fails the app is merely scanned, never broken.
     *
     * `.gitkeep` is preserved: skeleton/.gitignore ignores `/bootstrap/cache/firefly/*` but negates that
     * one file, so removing it would drop the directory out of the user's very first commit.
     *
     * @return list<string>
     */
    private function invalidateCompiledManifests(string $directory): array
    {
        if (! $this->archetype->reshapesFiles()) {
            return [];
        }

        $dir = $directory.'/bootstrap/cache/firefly';
        if (! is_dir($dir)) {
            return [];
        }

        $removed = 0;
        foreach ((array) scandir($dir) as $entry) {
            if (! is_string($entry) || $entry === '.' || $entry === '..' || $entry === '.gitkeep') {
                continue;
            }
            if (Filesystem::delete($dir.'/'.$entry)) {
                $removed++;
            }
        }

        return $removed === 0 ? [] : ['invalidated the compiled manifests firefly:cache wrote before the prune'];
    }

    /**
     * The version constraint to write for a newly required firefly package.
     *
     * Read from the generated manifest rather than hard-coded, because the right answer changes over the
     *
     * project's life: the skeleton pins `*@dev` while the family is pre-Packagist and will pin `^26.0` after
     * the first tagged release. Copying whatever firefly/firefly (or, failing that, any firefly/* package)
     * is already pinned to means the added lines always agree with the ones the skeleton shipped, and this
     * file never has to be edited for a release.
     *
     * @param  array<string, string>  $require
     */
    private function fireflyConstraint(array $require): string
    {
        if (isset($require['firefly/firefly'])) {
            return $require['firefly/firefly'];
        }
        foreach ($require as $package => $version) {
            if (str_starts_with($package, 'firefly/')) {
                return $version;
            }
        }

        return '*';
    }

    /**
     * Composer's own `config.sort-packages` ordering — platform requirements (php, ext-*, lib-*, composer-*)
     * first, then everything else by natural case-insensitive name. The skeleton turns sort-packages on, so
     * writing the block back in any other order would produce a spurious diff the first time the user runs
     * `composer require`.
     *
     * @param  array<string, string>  $packages
     * @return array<string, string>
     */
    private function sortPackages(array $packages): array
    {
        uksort($packages, static function (string $a, string $b): int {
            $rank = static fn (string $name): string => preg_match(
                '/^(?:php(?:-64bit|-ipv6|-zts|-debug)?|hhvm|(?:ext|lib)-[\p{L}\p{N}\p{Pd}_.]+|composer(?:-(?:plugin|runtime)-api)?)$/iD',
                $name,
            ) === 1 ? '0-'.$name : '1-'.$name;

            return strnatcasecmp($rank($a), $rank($b));
        });

        return $packages;
    }

    /**
     * @return array<string, string>
     */
    private function stringMap(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $map = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && is_string($item)) {
                $map[$key] = $item;
            }
        }

        return $map;
    }
}
