<?php

declare(strict_types=1);

namespace Firefly\Installer;

use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;

final class SymfonyProcessRunner implements ProcessRunner
{
    public function __construct(private readonly OutputInterface $output) {}

    public function run(array $command, ?string $cwd = null): int
    {
        $process = new Process($command, $cwd, timeout: null);

        if (Process::isTtySupported()) {
            $process->setTty(true);
        }

        return $process->run(function (string $type, string $buffer): void {
            $this->output->write($buffer);
        });
    }
}
