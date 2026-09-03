<?php

declare(strict_types=1);

use Firefly\Installer\NewCommand;
use Firefly\Installer\Tests\Support\FakeProcessRunner;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * @param  array<string, mixed>  $input
 * @param  list<string>  $answers  keystrokes for an INTERACTIVE run; [] runs non-interactively
 */
function runNew(FakeProcessRunner $runner, array $input, array $answers = []): CommandTester
{
    $command = new NewCommand($runner);
    (new Application)->addCommand($command);
    $tester = new CommandTester($command);
    if ($answers !== []) {
        $tester->setInputs($answers);
    }
    // CommandTester is interactive by default. `new` now prompts for the archetype and the capability
    // list when neither is flagged, so a test that means "just run it" has to say so explicitly —
    // otherwise every legacy case below would block on a question it never meant to answer.
    $tester->execute($input, $answers === [] ? ['interactive' => false] : []);

    return $tester;
}

it('shells the exact composer create-project invocation for a fresh dir', function () {
    $dir = sys_get_temp_dir().'/fnew-'.bin2hex(random_bytes(5));
    $runner = new FakeProcessRunner;

    $tester = runNew($runner, ['name' => $dir, '--no-git' => true]);

    $tester->assertCommandIsSuccessful();
    expect($runner->calls[0]['command'])->toBe(
        ['composer', 'create-project', 'firefly/skeleton', $dir, '--no-interaction']
    );
});

it('adds --stability=dev when --dev is passed', function () {
    $dir = sys_get_temp_dir().'/fnew-'.bin2hex(random_bytes(5));
    $runner = new FakeProcessRunner;

    runNew($runner, ['name' => $dir, '--dev' => true, '--no-git' => true]);

    expect($runner->calls[0]['command'])->toContain('--stability=dev');
});

it('runs git init after create-project unless --no-git', function () {
    $dir = sys_get_temp_dir().'/fnew-'.bin2hex(random_bytes(5));
    $runner = new FakeProcessRunner;

    runNew($runner, ['name' => $dir]); // --git is the default

    $programs = array_map(fn (array $c): string => $c['command'][0], $runner->calls);
    expect($programs)->toContain('composer')->toContain('git');
});

it('refuses a non-empty target directory without --force', function () {
    $dir = sys_get_temp_dir().'/fnew-'.bin2hex(random_bytes(5));
    mkdir($dir, 0o755, true);
    file_put_contents($dir.'/keep.txt', 'x');
    $runner = new FakeProcessRunner;

    $tester = runNew($runner, ['name' => $dir]);

    expect($tester->getStatusCode())->toBe(1)
        ->and($tester->getDisplay())->toContain('already exists')
        ->and($runner->calls)->toBeEmpty(); // nothing shelled out

    exec('rm -rf '.escapeshellarg($dir));
});

/**
 * THE REGRESSION. `--force` used to skip the installer's own "directory is not empty" error and then hand
 * the still-non-empty directory to `composer create-project`, which refuses it too ("Project directory ...
 * is not empty.") and has no flag that says otherwise. The promise could never be kept; the user got a
 * confusing composer error instead of a clear installer one. --force now empties the directory itself.
 */
it('empties the target directory under --force before create-project runs', function () {
    $dir = sys_get_temp_dir().'/fnew-'.bin2hex(random_bytes(5));
    mkdir($dir.'/nested/deeper', 0o755, true);
    file_put_contents($dir.'/keep.txt', 'x');
    file_put_contents($dir.'/.hidden', 'x');
    file_put_contents($dir.'/nested/deeper/buried.txt', 'x');
    $runner = new FakeProcessRunner;

    try {
        $tester = runNew($runner, ['name' => $dir, '--force' => true, '--no-git' => true]);

        $tester->assertCommandIsSuccessful();
        expect(is_dir($dir))->toBeTrue()                     // the directory itself survives
            ->and(scandir($dir))->toBe(['.', '..'])          // ...but nothing inside it does
            ->and($runner->calls[0]['command'])->toBe(
                ['composer', 'create-project', 'firefly/skeleton', $dir, '--no-interaction']
            );
    } finally {
        exec('rm -rf '.escapeshellarg($dir));
    }
});

it('warns exactly what --force is about to delete', function () {
    $dir = sys_get_temp_dir().'/fnew-'.bin2hex(random_bytes(5));
    mkdir($dir, 0o755, true);
    file_put_contents($dir.'/keep.txt', 'x');

    try {
        $tester = runNew(new FakeProcessRunner, ['name' => $dir, '--force' => true, '--no-git' => true]);

        expect($tester->getDisplay())->toContain('permanently deleted')->toContain($dir);
    } finally {
        exec('rm -rf '.escapeshellarg($dir));
    }
});

it('deletes nothing when the interactive --force confirmation is declined', function () {
    $dir = sys_get_temp_dir().'/fnew-'.bin2hex(random_bytes(5));
    mkdir($dir, 0o755, true);
    file_put_contents($dir.'/keep.txt', 'x');
    $runner = new FakeProcessRunner;

    try {
        $tester = runNew($runner, ['name' => $dir, '--force' => true, '--no-git' => true], answers: ['no']);

        expect($tester->getStatusCode())->toBe(1)
            ->and(is_file($dir.'/keep.txt'))->toBeTrue()
            ->and($runner->calls)->toBeEmpty();
    } finally {
        exec('rm -rf '.escapeshellarg($dir));
    }
});

it('refuses to empty the home directory even with --force', function () {
    $dir = sys_get_temp_dir().'/fnew-'.bin2hex(random_bytes(5));
    mkdir($dir, 0o755, true);
    file_put_contents($dir.'/precious.txt', 'x');
    $home = getenv('HOME');
    putenv("HOME={$dir}");
    $runner = new FakeProcessRunner;

    try {
        $tester = runNew($runner, ['name' => $dir, '--force' => true, '--no-git' => true]);

        expect($tester->getStatusCode())->toBe(1)
            ->and($tester->getDisplay())->toContain('Refusing to empty')
            ->and(is_file($dir.'/precious.txt'))->toBeTrue()
            ->and($runner->calls)->toBeEmpty();
    } finally {
        is_string($home) ? putenv("HOME={$home}") : putenv('HOME');
        exec('rm -rf '.escapeshellarg($dir));
    }
});

it('never shells a git command when --no-git is passed', function () {
    $dir = sys_get_temp_dir().'/fnew-'.bin2hex(random_bytes(5));
    $runner = new FakeProcessRunner;

    $tester = runNew($runner, ['name' => $dir, '--no-git' => true]);

    $tester->assertCommandIsSuccessful();
    $programs = array_map(fn (array $c): string => $c['command'][0], $runner->calls);
    expect($runner->calls)->toHaveCount(1)
        ->and($programs)->not->toContain('git');
});

it('rejects two archetype flags at once instead of silently picking one', function () {
    $dir = sys_get_temp_dir().'/fnew-'.bin2hex(random_bytes(5));
    $runner = new FakeProcessRunner;

    $tester = runNew($runner, ['name' => $dir, '--api' => true, '--full' => true, '--no-git' => true]);

    expect($tester->getStatusCode())->toBe(1)
        ->and($tester->getDisplay())->toContain('mutually exclusive')
        ->and($runner->calls)->toBeEmpty();
});

it('rejects an unknown capability and names the ones that exist', function () {
    $dir = sys_get_temp_dir().'/fnew-'.bin2hex(random_bytes(5));
    $runner = new FakeProcessRunner;

    $tester = runNew($runner, ['name' => $dir, '--with' => ['security,teleportation'], '--no-git' => true]);

    expect($tester->getStatusCode())->toBe(1)
        ->and($tester->getDisplay())->toContain('Unknown capability')
        ->and($tester->getDisplay())->toContain('scheduling')
        ->and($runner->calls)->toBeEmpty();
});

it('stays fully non-interactive with no archetype flags, defaulting to web', function () {
    $dir = sys_get_temp_dir().'/fnew-'.bin2hex(random_bytes(5));
    $runner = new FakeProcessRunner;

    $tester = runNew($runner, ['name' => $dir, '--no-git' => true]);

    $tester->assertCommandIsSuccessful();
    expect($tester->getDisplay())->toContain('web')
        ->and($tester->getDisplay())->not->toContain('Which application shape?');
});

it('prompts for the archetype and the capabilities when the terminal is interactive', function () {
    $dir = sys_get_temp_dir().'/fnew-'.bin2hex(random_bytes(5));
    $runner = new FakeProcessRunner;

    $tester = runNew($runner, ['name' => $dir, '--no-git' => true], answers: ['api', 'security,eda']);

    $tester->assertCommandIsSuccessful();
    expect($tester->getDisplay())
        ->toContain('Which application shape?')
        ->toContain('Which capabilities?')
        ->toContain('api')
        ->toContain('security, eda');
});
