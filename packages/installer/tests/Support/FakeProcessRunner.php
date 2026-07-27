<?php

declare(strict_types=1);

namespace Firefly\Installer\Tests\Support;

use Firefly\Installer\ProcessRunner;

final class FakeProcessRunner implements ProcessRunner
{
    /** @var list<array{command: list<string>, cwd: ?string}> */
    public array $calls = [];

    public function __construct(private readonly int $exitCode = 0) {}

    public function run(array $command, ?string $cwd = null): int
    {
        $this->calls[] = ['command' => $command, 'cwd' => $cwd];

        return $this->exitCode;
    }
}
