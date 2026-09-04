<?php

declare(strict_types=1);

use Firefly\Installer\NewCommand;
use Firefly\Installer\Tests\Support\FakeProcessRunner;
use Firefly\Installer\Tests\Support\Skeleton;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Drives `firefly new` end-to-end with a fake `composer create-project` that materialises the monorepo's
 * REAL skeleton/ into the target directory, then asserts the two things an archetype is: the composer.json
 * it produces, and the file set it leaves behind.
 *
 * No composer, no network, no git — the ProcessRunner seam records the argv and the Skeleton support class
 * supplies the bytes create-project would have written.
 *
 * @param  array<string, mixed>  $input
 * @param  string|null  $source  the directory the fake create-project materialises; the real skeleton by default
 * @return array{tester: CommandTester, dir: string, runner: FakeProcessRunner}
 */
function generate(array $input, ?string $source = null): array
{
    $dir = sys_get_temp_dir().'/farch-'.bin2hex(random_bytes(6)).'/app';
    $runner = Skeleton::creatingRunner($source ?? Skeleton::path());
    $command = new NewCommand($runner);
    (new Application)->addCommand($command);
    $tester = new CommandTester($command);
    $tester->execute(['name' => $dir, '--no-git' => true, ...$input], ['interactive' => false]);

    return ['tester' => $tester, 'dir' => $dir, 'runner' => $runner];
}

/**
 * A private copy of the real skeleton that a test may mutate before create-project "produces" it — used to
 * reproduce the two states the monorepo checkout never shows: a skeleton whose post-create-project-cmd has
 * already run firefly:cache, and one whose tests/ was export-ignored out of the tarball.
 *
 * @param  callable(string): void  $mutate
 */
function skeletonWhere(callable $mutate): string
{
    $source = sys_get_temp_dir().'/fsrc-'.bin2hex(random_bytes(6));
    Skeleton::copy(Skeleton::path(), $source);
    $mutate($source);

    return $source;
}

function cleanUp(string $dir): void
{
    exec('rm -rf '.escapeshellarg(dirname($dir)));
}

it('leaves the skeleton exactly as shipped for the default web archetype', function () {
    ['tester' => $tester, 'dir' => $dir] = generate([]);

    try {
        $tester->assertCommandIsSuccessful();

        // Same file set as the skeleton, byte-for-byte except the manifest the archetype stamps.
        expect(Skeleton::files($dir))->toBe(Skeleton::files(Skeleton::path()))
            ->and(Skeleton::stamp($dir))->toBe(['archetype' => 'web', 'capabilities' => []])
            ->and(Skeleton::requirements($dir))->toBe(Skeleton::requirements(Skeleton::path()));
    } finally {
        cleanUp($dir);
    }
});

it('strips the view layer, the welcome page and its test for --api', function () {
    ['tester' => $tester, 'dir' => $dir] = generate(['--api' => true]);

    try {
        $tester->assertCommandIsSuccessful();
        $files = Skeleton::files($dir);

        // Non-vacuity guard: a `not->toContain()` on a path the skeleton no longer ships would pass while
        // the archetype quietly pruned nothing. Assert the skeleton HAS these first, so renaming any of
        // them upstream fails here instead of in a user's generated project.
        expect(Skeleton::files(Skeleton::path()))
            ->toContain('app/Http/WelcomeController.php')
            ->toContain('resources/views/welcome.blade.php')
            ->toContain('tests/Feature/WelcomeTest.php');

        expect($files)
            ->not->toContain('app/Http/WelcomeController.php')
            ->not->toContain('resources/views/welcome.blade.php')
            ->not->toContain('tests/Feature/WelcomeTest.php')
            // the JSON slice is the whole point of the archetype and must survive
            ->toContain('app/Http/GreetingController.php')
            ->toContain('app/GreetingService.php')
            // ...and the welcome test is replaced, not merely deleted: a generated project whose first
            // `composer test` is red is a worse first impression than one with no view layer.
            ->toContain('tests/Feature/ApiSmokeTest.php');

        // resources/ held nothing but the welcome view, so the empty shell goes with it.
        expect(is_dir($dir.'/resources'))->toBeFalse();

        expect(Skeleton::stamp($dir))->toBe(['archetype' => 'api', 'capabilities' => []]);
    } finally {
        cleanUp($dir);
    }
});

it('writes an api smoke test that actually compiles against the skeleton it replaces', function () {
    ['tester' => $tester, 'dir' => $dir] = generate(['--api' => true]);

    try {
        $stub = (string) file_get_contents($dir.'/tests/Feature/ApiSmokeTest.php');
        $skeleton = Skeleton::path();

        // The stub extends the skeleton's own base test case and exercises the skeleton's own sample route.
        // If either is renamed, this fails here rather than in a user's freshly generated project.
        expect($stub)->toContain('namespace Tests\Feature;')->toContain('extends TestCase')
            ->and(file_get_contents($skeleton.'/tests/TestCase.php'))->toContain('namespace Tests;')
            ->and(file_get_contents($skeleton.'/app/Http/GreetingController.php'))->toContain('/greetings/{name}')
            ->and($stub)->toContain('/greetings/Ada');
    } finally {
        cleanUp($dir);
    }
});

it('adds exactly the requested capabilities, at the constraint the skeleton already uses', function () {
    ['tester' => $tester, 'dir' => $dir] = generate(['--with' => ['security,eda']]);

    try {
        $tester->assertCommandIsSuccessful();
        $require = Skeleton::requirements($dir);

        expect($require)->toHaveKey('firefly/security')->toHaveKey('firefly/eda')
            // the constraint is copied off firefly/firefly, so it tracks the skeleton across releases
            // instead of pinning a literal that goes stale the day the family reaches 1.0
            ->and($require['firefly/security'])->toBe($require['firefly/firefly'])
            ->and($require['firefly/eda'])->toBe($require['firefly/firefly'])
            // nothing else crept in
            ->and(array_values(array_filter(array_keys($require), fn (string $p): bool => str_starts_with($p, 'firefly/'))))
            ->toBe(['firefly/cli', 'firefly/eda', 'firefly/firefly', 'firefly/security'])
            ->and(Skeleton::stamp($dir)['capabilities'])->toBe(['security', 'eda']);

        // composer's sort-packages ordering: platform first, then natural case-insensitive name.
        expect(array_keys($require))->toBe(['php', 'firefly/cli', 'firefly/eda', 'firefly/firefly', 'firefly/security', 'laravel/framework']);
    } finally {
        cleanUp($dir);
    }
});

it('pulls an adapter capability port in with it', function () {
    ['tester' => $tester, 'dir' => $dir] = generate(['--with' => ['eda-kafka']]);

    try {
        $tester->assertCommandIsSuccessful();
        // A Kafka publisher with no EventPublisher port to implement is not a shape worth generating.
        expect(Skeleton::stamp($dir)['capabilities'])->toBe(['eda', 'eda-kafka'])
            ->and(Skeleton::requirements($dir))->toHaveKey('firefly/eda')->toHaveKey('firefly/eda-kafka');
    } finally {
        cleanUp($dir);
    }
});

it('pre-wires every non-adapter capability for --full while keeping the web file set', function () {
    ['tester' => $tester, 'dir' => $dir] = generate(['--full' => true]);

    try {
        $tester->assertCommandIsSuccessful();

        expect(Skeleton::files($dir))->toContain('app/Http/WelcomeController.php')
            ->toContain('resources/views/welcome.blade.php');

        // Adapters bind the app to one broker or engine; --full cannot make that choice for the user.
        expect(Skeleton::requirements($dir))
            ->toHaveKey('firefly/security')->toHaveKey('firefly/eda')->toHaveKey('firefly/scheduling')
            ->toHaveKey('firefly/admin')->toHaveKey('firefly/resilience')
            ->not->toHaveKey('firefly/eda-kafka')
            ->not->toHaveKey('firefly/eda-rabbitmq')
            ->not->toHaveKey('firefly/scheduling-postgres')
            // the test kit is a dev dependency and lands on the right side of the manifest
            ->not->toHaveKey('firefly/testing');
        expect(Skeleton::requirements($dir, 'require-dev'))->toHaveKey('firefly/testing')
            ->and(Skeleton::stamp($dir)['archetype'])->toBe('full');
    } finally {
        cleanUp($dir);
    }
});

it('combines --api with --with instead of making the user choose', function () {
    ['tester' => $tester, 'dir' => $dir] = generate(['--api' => true, '--with' => ['security', 'cqrs']]);

    try {
        $tester->assertCommandIsSuccessful();
        expect(Skeleton::files($dir))->not->toContain('resources/views/welcome.blade.php');
        expect(Skeleton::stamp($dir))
            ->toBe(['archetype' => 'api', 'capabilities' => ['security', 'cqrs']]);
    } finally {
        cleanUp($dir);
    }
});

it('produces a composer.json composer itself can still parse', function () {
    ['dir' => $dir] = generate(['--full' => true]);

    try {
        $raw = (string) file_get_contents($dir.'/composer.json');
        expect(json_decode($raw, true))->toBeArray()
            ->and(str_ends_with($raw, "}\n"))->toBeTrue()   // trailing newline, as composer writes it
            ->and($raw)->toContain('    "require": {');      // four-space indent, as composer writes it
    } finally {
        cleanUp($dir);
    }
});

/**
 * THE REGRESSION, verified end-to-end against a real `composer create-project` before it was fixed.
 *
 * The skeleton's post-create-project-cmd ends in `php artisan firefly:cache`, so create-project hands back
 * a project whose compiled routes.php and component.php already name App\Http\WelcomeController — the
 * class `--api` is about to delete. Left in place, the generated project answered `GET /` with a 500
 * ("Target class [App\Http\WelcomeController] does not exist") instead of a 404, because the compiled
 * manifest outlived the class it pointed at.
 */
it('drops the compiled manifests that name the class it just pruned', function () {
    $source = skeletonWhere(static function (string $skeleton): void {
        $cache = $skeleton.'/bootstrap/cache/firefly';
        // Exactly what `php artisan firefly:cache` left behind, including a proxies/ subdirectory.
        file_put_contents($cache.'/routes.php', "<?php return ['/' => [App\\Http\\WelcomeController::class, 'index']];");
        file_put_contents($cache.'/component.php', '<?php return [App\\Http\\WelcomeController::class];');
        mkdir($cache.'/proxies', 0o755, true);
        file_put_contents($cache.'/proxies/Stale.php', '<?php');
    });

    ['tester' => $tester, 'dir' => $dir] = generate(['--api' => true], $source);

    try {
        $tester->assertCommandIsSuccessful();
        $cache = $dir.'/bootstrap/cache/firefly';

        expect(is_file($cache.'/routes.php'))->toBeFalse()
            ->and(is_file($cache.'/component.php'))->toBeFalse()
            ->and(is_dir($cache.'/proxies'))->toBeFalse()
            // .gitkeep survives: skeleton/.gitignore ignores the directory's contents but negates this one
            // file, so removing it would drop bootstrap/cache/firefly out of the user's first commit.
            ->and(is_file($cache.'/.gitkeep'))->toBeTrue();
    } finally {
        cleanUp($dir);
        exec('rm -rf '.escapeshellarg($source));
    }
});

it('leaves the compiled manifests alone for an archetype that reshapes nothing', function () {
    $source = skeletonWhere(static function (string $skeleton): void {
        file_put_contents($skeleton.'/bootstrap/cache/firefly/routes.php', '<?php return [];');
    });

    ['tester' => $tester, 'dir' => $dir, 'runner' => $runner] = generate([], $source);

    try {
        $tester->assertCommandIsSuccessful();
        // web prunes nothing, so nothing it compiled has gone stale and there is no reason to pay for a
        // second `firefly:cache` run in a command the user is watching.
        expect(is_file($dir.'/bootstrap/cache/firefly/routes.php'))->toBeTrue();

        $programs = array_map(static fn (array $c): string => implode(' ', $c['command']), $runner->calls);
        expect($programs)->not->toContain('php artisan firefly:cache');
    } finally {
        cleanUp($dir);
        exec('rm -rf '.escapeshellarg($source));
    }
});

it('recompiles the manifests it invalidated, in the generated project', function () {
    ['tester' => $tester, 'dir' => $dir, 'runner' => $runner] = generate(['--api' => true]);

    try {
        $tester->assertCommandIsSuccessful();

        $cache = array_values(array_filter(
            $runner->calls,
            static fn (array $c): bool => $c['command'] === ['php', 'artisan', 'firefly:cache'],
        ));

        // ...and in the NEW project's directory, not the installer's cwd.
        expect($cache)->toHaveCount(1)
            ->and($cache[0]['cwd'])->toBe($dir);
    } finally {
        cleanUp($dir);
    }
});

/**
 * `skeleton/.gitattributes` marks `/tests export-ignore`, so a real `composer create-project` ships no
 * tests/ directory — no tests/TestCase.php for `extends TestCase` to resolve. Copying the api smoke test in
 * anyway turned the generated project's first `composer test` from the web baseline's "Test directory not
 * found" (exit 2) into a `Class "Tests\TestCase" not found` FATAL (exit 255), which is worse than adding
 * nothing at all. Both states were reproduced against a real create-project.
 */
it('skips the api smoke test when the generated project ships no base test case', function () {
    $source = skeletonWhere(static function (string $skeleton): void {
        exec('rm -rf '.escapeshellarg($skeleton.'/tests'));
    });

    ['tester' => $tester, 'dir' => $dir] = generate(['--api' => true], $source);

    try {
        $tester->assertCommandIsSuccessful();

        expect(is_file($dir.'/tests/Feature/ApiSmokeTest.php'))->toBeFalse()
            ->and(is_dir($dir.'/tests'))->toBeFalse()
            // and it says so, rather than silently doing nothing
            ->and($tester->getDisplay())->toContain('tests/TestCase.php');
    } finally {
        cleanUp($dir);
        exec('rm -rf '.escapeshellarg($source));
    }
});

it('still writes the api smoke test when the base test case is there', function () {
    ['dir' => $dir] = generate(['--api' => true]);

    try {
        // Non-vacuity guard for the case above: the prerequisite the applier checks must be a file the
        // skeleton actually ships, or the skip branch would be the only branch that ever runs.
        expect(is_file(Skeleton::path().'/tests/TestCase.php'))->toBeTrue()
            ->and(is_file($dir.'/tests/Feature/ApiSmokeTest.php'))->toBeTrue();
    } finally {
        cleanUp($dir);
    }
});
