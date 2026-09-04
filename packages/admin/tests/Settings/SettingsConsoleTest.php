<?php

declare(strict_types=1);

use Firefly\Admin\Settings\FeatureToggle;
use Firefly\Admin\Settings\SettingsConsole;
use Firefly\Admin\Settings\SettingsSettings;
use Firefly\Config\Config;
use Illuminate\Config\Repository;

/**
 * The one dashboard page that CHANGES the application rather than describing it, and the three gates in
 * front of it.
 *
 * The third gate is the interesting one: `app.env` is checked in PHP and there is no configuration key that
 * lifts it. That is the difference between "we made it safe" and "we made it configurable to be safe", and
 * only the first survives someone copying a .env into production.
 */
/**
 * @param  array<string, mixed>  $config
 */
function settingsConsole(array $config, string $environment = 'local', ?string $dir = null): SettingsConsole
{
    $repository = new Repository(['app' => ['env' => $environment], ...$config]);
    $wrapped = new Config($repository);

    return new SettingsConsole(
        $wrapped,
        $repository,
        SettingsSettings::fromConfig($wrapped),
        $dir ?? sys_get_temp_dir().'/firefly-settings-'.bin2hex(random_bytes(6)),
    );
}

/** @return array<string, mixed> */
function settingsOn(bool $writable = false): array
{
    return ['firefly' => [
        'admin' => ['settings' => ['enabled' => true, 'writable' => $writable]],
        'openapi' => ['enabled' => true],
    ]];
}

function settingsDir(): string
{
    return sys_get_temp_dir().'/firefly-settings-'.bin2hex(random_bytes(6));
}

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/firefly-settings-*/'.SettingsConsole::FILE) ?: [] as $file) {
        @unlink($file);
        @rmdir(dirname($file));
    }
});

it('is switched off by default, and reports every switch when it is on', function () {
    // Off unlike every other dashboard page, because the others describe the application and this one
    // changes it — a surface that alters a running system should never appear because a debug flag was left
    // on somewhere.
    expect(settingsConsole([])->isEnabled())->toBeFalse()
        ->and(settingsConsole(settingsOn())->isEnabled())->toBeTrue()
        ->and(settingsConsole(settingsOn())->toggles())->toHaveCount(count(FeatureToggle::all()));
});

it('separates seeing a switch from being able to flip it', function () {
    $readOnly = settingsConsole(settingsOn());

    expect($readOnly->isWritable())->toBeFalse()
        ->and($readOnly->set('firefly.openapi.enabled', false))->toContain('writable')
        ->and($readOnly->overrides())->toBe([]);
});

it('refuses every write in production, whatever the configuration says', function (string $environment) {
    $live = settingsConsole(settingsOn(writable: true), $environment);

    expect($live->isProduction())->toBeTrue()
        ->and($live->isWritable())->toBeFalse()
        ->and($live->set('firefly.openapi.enabled', false))->toContain('production')
        ->and($live->overrides())->toBe([]);
})->with(['production', 'prod', 'PRODUCTION']);

it('cannot express a write to a key nobody put on the list', function () {
    $writable = settingsConsole(settingsOn(writable: true));

    // The check is against the fixed list, not a pattern — which is what makes this a feature switch rather
    // than a remote configuration endpoint. A crafted POST naming a database host or the app key finds
    // nothing to write.
    foreach (['app.key', 'database.connections.mysql.host', 'logging.channels.stack.path', 'firefly.openapi'] as $key) {
        expect($writable->set($key, true))->toContain('not a switch');
    }

    expect($writable->overrides())->toBe([]);
});

it('writes an override, reports it as the source, and clears it exactly', function () {
    $writable = settingsConsole(settingsOn(writable: true), 'local', settingsDir());

    expect($writable->set('firefly.openapi.enabled', false))->toContain('off')
        ->and($writable->overrides())->toBe(['firefly.openapi.enabled' => false]);

    $rows = array_values(array_filter(
        $writable->toggles(),
        static fn (array $candidate): bool => $candidate['toggle']->key === 'firefly.openapi.enabled',
    ));

    expect($rows)->toHaveCount(1);
    $row = $rows[0];

    expect($row['value'])->toBeFalse()
        // The page must never show a value without saying where it came from: an override that looked like
        // configuration would send someone hunting through files for a setting this page invented.
        ->and($row['source'])->toBe('console')
        ->and($row['overridden'])->toBeTrue();

    expect($writable->reset())->toContain('Cleared')
        ->and($writable->overrides())->toBe([])
        ->and(is_file($writable->file()))->toBeFalse();
});

it('ignores anything in the override file that it would not have written', function () {
    $dir = settingsDir();
    mkdir($dir, 0o775, true);

    // The file is on disk and a person can edit it. Filtering on the way IN as well as out means a
    // hand-written entry — or one left by an older version of the list — cannot introduce a key the console
    // would have refused, and cannot smuggle a non-boolean into config().
    file_put_contents($dir.'/'.SettingsConsole::FILE, (string) json_encode([
        'firefly.openapi.enabled' => false,
        'app.key' => 'base64:planted',
        'firefly.admin.data.enabled' => 'yes please',
    ]));

    expect(settingsConsole(settingsOn(writable: true), 'local', $dir)->overrides())
        ->toBe(['firefly.openapi.enabled' => false]);
});

it('survives an override file that is not json at all', function () {
    $dir = settingsDir();
    mkdir($dir, 0o775, true);
    file_put_contents($dir.'/'.SettingsConsole::FILE, 'this is not json');

    expect(settingsConsole(settingsOn(), 'local', $dir)->overrides())->toBe([]);
});

it('merges its overrides into the live configuration', function () {
    $dir = settingsDir();
    $repository = new Repository(['app' => ['env' => 'local'], ...settingsOn(writable: true)]);
    $wrapped = new Config($repository);
    $writable = new SettingsConsole($wrapped, $repository, SettingsSettings::fromConfig($wrapped), $dir);

    $writable->set('firefly.openapi.enabled', false);
    $writable->apply();

    // apply() is called from the provider's register(), before any settings object is built. It was
    // originally called from the dashboard's own boot pass, which wrote the file and showed the new state on
    // the page while /openapi.json kept answering 200 — every settings object in the framework is
    // constructed once from config and held, so a merge after the first read changes nothing.
    expect($repository->get('firefly.openapi.enabled'))->toBeFalse();

    @unlink($writable->file());
    @rmdir($dir);
});
