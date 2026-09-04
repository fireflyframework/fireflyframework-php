<?php

declare(strict_types=1);

namespace Firefly\Admin\Settings;

use Firefly\Config\Config;
use Illuminate\Contracts\Config\Repository;
use Throwable;

/**
 * Reads the framework's feature switches, and — only where that is defensible — flips them.
 *
 * THREE GATES, AND THE THIRD IS NOT A CONFIG KEY. `firefly.admin.settings.enabled` decides whether the page
 * exists at all; `firefly.admin.settings.writable` decides whether it has controls; and `app.env` decides
 * whether a write is possible AT ALL — in production it is refused whatever the other two say, and there is
 * no key that lifts that. A dashboard that can change a running application's behaviour is a remote-control
 * surface, and one reachable in production is a vulnerability regardless of how carefully it is configured.
 * Making the last gate unconfigurable is the difference between "we made it safe" and "we made it
 * configurable to be safe", and only the first survives someone copying a .env.
 *
 * WHAT A WRITE ACTUALLY DOES. PHP shares nothing between requests, so setting `config()` would last exactly
 * as long as the response. An override is therefore written to ONE json file under the cache directory and
 * merged back over configuration at boot. That file is the whole persistent surface: it holds only keys from
 * FeatureToggle's fixed list, only booleans, it is listed on the page so an override is never invisible, and
 * deleting it restores the configured values exactly.
 *
 * WHY NOT WRITE TO `.env`. Because a config cache would then disagree with it until someone re-ran
 * `config:clear`, because the file is routinely read-only in a container image, and because a web form that
 * edits the file holding your database password is not a feature.
 */
final class SettingsConsole
{
    public const string FILE = 'firefly-admin-overrides.json';

    public function __construct(
        private readonly Config $config,
        private readonly Repository $repository,
        private readonly SettingsSettings $settings,
        private readonly string $storagePath,
    ) {}

    public function isEnabled(): bool
    {
        return $this->settings->enabled;
    }

    /** Writes need the key AND a non-production environment; the second is not configurable. */
    public function isWritable(): bool
    {
        return $this->settings->enabled && $this->settings->writable && ! $this->settings->production;
    }

    public function isProduction(): bool
    {
        return $this->settings->production;
    }

    /**
     * Every toggle with its effective value and where that value came from.
     *
     * @return list<array{toggle: FeatureToggle, value: bool, source: string, overridden: bool}>
     */
    public function toggles(): array
    {
        $overrides = $this->overrides();

        $rows = [];
        foreach (FeatureToggle::all() as $toggle) {
            $overridden = array_key_exists($toggle->key, $overrides);

            $rows[] = [
                'toggle' => $toggle,
                'value' => $overridden ? $overrides[$toggle->key] : $this->config->bool($toggle->key, $toggle->default),
                'source' => match (true) {
                    $overridden => 'console',
                    $this->config->has($toggle->key) => 'config',
                    default => 'default',
                },
                'overridden' => $overridden,
            ];
        }

        return $rows;
    }

    /**
     * Set one toggle, or report why not.
     *
     * The key is checked against the fixed list rather than against a pattern, so a crafted POST naming
     * `app.key` finds nothing to write — this method cannot express a write to a key nobody put on the list.
     */
    public function set(string $key, bool $value): string
    {
        if (! $this->isWritable()) {
            return $this->settings->production
                ? 'Refused: this application is running in production, where the console is read-only whatever the configuration says.'
                : 'Refused: set firefly.admin.settings.writable to permit changes.';
        }

        if (FeatureToggle::find($key) === null) {
            return 'Refused: that is not a switch this console offers.';
        }

        $overrides = $this->overrides();
        $overrides[$key] = $value;

        return $this->persist($overrides)
            ? sprintf('%s is now %s. It will apply from the next request.', $key, $value ? 'on' : 'off')
            : 'The override could not be written. Check that the cache directory is writable.';
    }

    /** Drop every override, restoring the configured values exactly. */
    public function reset(): string
    {
        if (! $this->isWritable()) {
            return 'Refused: the console is read-only.';
        }

        $file = $this->file();

        if (! is_file($file)) {
            return 'There were no overrides to clear.';
        }

        return @unlink($file)
            ? 'Cleared every override. Configured values apply from the next request.'
            : 'The override file could not be removed.';
    }

    /**
     * The overrides currently on disk.
     *
     * Filtered on the way IN as well as on the way out: a file edited by hand, or left behind by an older
     * version of this list, cannot introduce a key the console would not have written.
     *
     * @return array<string, bool>
     */
    public function overrides(): array
    {
        $file = $this->file();

        if (! is_file($file) || ! is_readable($file)) {
            return [];
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode((string) file_get_contents($file), true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        if (! is_array($decoded)) {
            return [];
        }

        $overrides = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key) && is_bool($value) && FeatureToggle::find($key) !== null) {
                $overrides[$key] = $value;
            }
        }

        return $overrides;
    }

    /**
     * Merge the overrides over the live configuration.
     *
     * Called from the boot pass, BEFORE anything reads a setting — which is the only point at which this can
     * work, because every settings object in the framework is built once from config and held.
     */
    public function apply(): void
    {
        foreach ($this->overrides() as $key => $value) {
            $this->repository->set($key, $value);
        }
    }

    public function file(): string
    {
        return rtrim($this->storagePath, '/\\').'/'.self::FILE;
    }

    /**
     * @param  array<string, bool>  $overrides
     */
    private function persist(array $overrides): bool
    {
        $file = $this->file();
        $directory = dirname($file);

        if (! is_dir($directory) && ! @mkdir($directory, 0o775, true) && ! is_dir($directory)) {
            return false;
        }

        $json = json_encode($overrides, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return $json !== false && @file_put_contents($file, $json."\n") !== false;
    }
}
