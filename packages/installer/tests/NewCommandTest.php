<?php

declare(strict_types=1);

use Firefly\Installer\NewCommand;
use Firefly\Installer\Tests\Support\FakeProcessRunner;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

/** @param array<string, mixed> $input */
function runNew(FakeProcessRunner $runner, array $input): CommandTester
{
    $command = new NewCommand($runner);
    (new Application)->addCommand($command);
    $tester = new CommandTester($command);
    $tester->execute($input);

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
