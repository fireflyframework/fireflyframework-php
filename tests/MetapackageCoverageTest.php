<?php

declare(strict_types=1);
use Firefly\Installer\CapabilityCatalog;

/**
 * The runtime metapackage must reach every runtime package, and in particular every package the installer
 * offers as a capability.
 *
 * WHAT THIS CAUGHT. firefly/admin and firefly/openapi were built, tested, documented in the book and offered
 * by `firefly new --with admin,openapi` while NOTHING required them — so `composer create-project
 * firefly/skeleton` produced a project with no dashboard and no API documentation, and the welcome page
 * (which checks `class_exists()` before linking anything) simply omitted both cards. Every package suite
 * passed the whole time, because a package suite runs from the monorepo, where the source is on disk. A
 * composer manifest is the one artifact a monorepo cannot check by running its own tests.
 *
 * The second test below is the sharper one. CapabilityCatalog offers thirteen non-adapter capabilities, and
 * for eleven of them `--with` only makes an ALREADY-INSTALLED package an explicit dependency — the code
 * arrives with firefly/firefly either way. admin and openapi were the only two where `--with` decided
 * whether the code existed at all, so the same flag meant two different things depending on which
 * capability you named. That asymmetry is what actually broke the skeleton, and it is what this asserts
 * against.
 */
it('requires every runtime package from the firefly/firefly metapackage', function () {
    $excluded = [
        // The metapackage itself.
        'firefly/firefly',

        // Broker transports. Each binds the application to an infrastructure choice, and two of them cannot
        // even install without a platform extension or a client library present: eda-postgres requires
        // ext-pdo_pgsql and eda-rabbitmq pulls php-amqplib. An application picks its transport; the
        // framework does not pick one for it. CapabilityCatalog marks all four `adapter: true` and leaves
        // them out of --full for the same reason, so the two lists agree.
        'firefly/eda-kafka',
        'firefly/eda-postgres',
        'firefly/eda-rabbitmq',

        // A standalone Symfony Console binary, installed globally with `composer global require` to CREATE
        // projects. Requiring it from the runtime would install a project generator into every deployment.
        'firefly/installer',

        // Test-only: it pulls orchestra/testbench, which belongs in require-dev or nowhere.
        'firefly/testing',
    ];

    $required = requiredBy(dirname(__DIR__).'/packages/firefly/composer.json');

    $missing = [];
    foreach (packageNames() as $name) {
        if (! in_array($name, $excluded, true) && ! in_array($name, $required, true)) {
            $missing[] = $name;
        }
    }

    sort($missing);

    expect($missing)->toBe([]);

    // An exclusion naming a package that no longer exists is a stale comment pretending to be a decision.
    foreach ($excluded as $name) {
        expect(packageNames())->toContain($name);
    }
});

it('installs every non-adapter capability by default, so --with only ever makes a dependency explicit', function () {
    $required = requiredBy(dirname(__DIR__).'/packages/firefly/composer.json');

    $missing = [];
    foreach (CapabilityCatalog::all() as $capability) {
        if ($capability->adapter || $capability->dev) {
            continue;
        }

        if (! in_array($capability->package, $required, true)) {
            $missing[] = $capability->id;
        }
    }

    sort($missing);

    expect($missing)->toBe([]);
});

/** @return list<string> */
function requiredBy(string $composer): array
{
    /** @var mixed $json */
    $json = json_decode((string) file_get_contents($composer), true);
    $declared = is_array($json) ? ($json['require'] ?? null) : null;

    return is_array($declared) ? array_map(strval(...), array_keys($declared)) : [];
}

/** @return list<string> */
function packageNames(): array
{
    $names = [];
    foreach (glob(dirname(__DIR__).'/packages/*/composer.json') ?: [] as $composer) {
        /** @var mixed $json */
        $json = json_decode((string) file_get_contents($composer), true);
        if (is_array($json) && is_string($json['name'] ?? null)) {
            $names[] = $json['name'];
        }
    }

    return $names;
}
