<?php

// tests/SensitivePathGuardTest.php

declare(strict_types=1);

use Symfony\Component\Process\Process;

function runGuardIn(string $repo): Process
{
    $script = dirname(__DIR__).'/scripts/check-no-sensitive-tracked.sh';
    $p = new Process(['bash', $script], $repo);
    $p->run();

    return $p;
}

function makeGitSandbox(): string
{
    $repo = sys_get_temp_dir().'/guard-'.bin2hex(random_bytes(6));
    mkdir($repo, 0o755, true);
    foreach ([['init', '-q'], ['config', 'user.email', 't@t'], ['config', 'user.name', 't']] as $args) {
        (new Process(array_merge(['git'], $args), $repo))->run();
    }
    copy(dirname(__DIR__).'/scripts/check-no-sensitive-tracked.sh', $repo.'/guard.sh');

    return $repo;
}

it('passes on the real, clean repository tree', function () {
    $p = runGuardIn(dirname(__DIR__));
    expect($p->getExitCode())->toBe(0, $p->getErrorOutput());
});

it('catches a planted docs/superpowers path', function () {
    $repo = makeGitSandbox();

    try {
        mkdir($repo.'/docs/superpowers', 0o755, true);
        file_put_contents($repo.'/docs/superpowers/secret-plan.md', 'x');
        (new Process(['git', 'add', '-A'], $repo))->run();

        $p = new Process(['bash', $repo.'/guard.sh'], $repo);
        $p->run();

        expect($p->getExitCode())->not->toBe(0)
            ->and($p->getErrorOutput())->toContain('superpowers');
    } finally {
        (new Process(['rm', '-rf', $repo]))->run();
    }
});

it('allows .env.example but catches a real .env', function () {
    $repo = makeGitSandbox();

    try {
        file_put_contents($repo.'/.env.example', 'APP_KEY=');
        (new Process(['git', 'add', '-A'], $repo))->run();
        $allowed = new Process(['bash', $repo.'/guard.sh'], $repo);
        $allowed->run();
        expect($allowed->getExitCode())->toBe(0, $allowed->getErrorOutput());

        file_put_contents($repo.'/.env', 'SECRET=1');
        (new Process(['git', 'add', '-A', '-f'], $repo))->run();
        $p = new Process(['bash', $repo.'/guard.sh'], $repo);
        $p->run();
        expect($p->getExitCode())->not->toBe(0)->and($p->getErrorOutput())->toContain('env');
    } finally {
        (new Process(['rm', '-rf', $repo]))->run();
    }
});

it('catches a tracked private-key filename (id_rsa)', function () {
    $repo = makeGitSandbox();

    try {
        mkdir($repo.'/.ssh', 0o755, true);
        file_put_contents($repo.'/.ssh/id_rsa', '-----BEGIN OPENSSH PRIVATE KEY-----');
        (new Process(['git', 'add', '-A', '-f'], $repo))->run();

        $p = new Process(['bash', $repo.'/guard.sh'], $repo);
        $p->run();

        expect($p->getExitCode())->not->toBe(0)
            ->and($p->getErrorOutput())->toContain('key file');
    } finally {
        (new Process(['rm', '-rf', $repo]))->run();
    }
});
