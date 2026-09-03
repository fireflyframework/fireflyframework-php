<?php

declare(strict_types=1);

namespace Firefly\Installer\Tests\Support;

use Closure;
use Firefly\Installer\ProcessRunner;

final class FakeProcessRunner implements ProcessRunner
{
    /** @var list<array{command: list<string>, cwd: ?string}> */
    public array $calls = [];

    /**
     * @param  int  $exitCode  the code every simulated process returns
     * @param  (Closure(list<string>, ?string): void)|null  $onRun  a side effect to perform per invocation
     *
     * The $onRun hook exists so a test can make the fake `composer create-project` actually PRODUCE a
     * project (see Skeleton::creatingRunner()). Without it the archetype shaping — which edits the
     * generated composer.json and prunes generated files — has nothing to act on, and the only thing a test
     * could assert about `firefly new --api` is the argv, which is precisely the half that was never broken.
     */
    public function __construct(
        private readonly int $exitCode = 0,
        private readonly ?Closure $onRun = null,
    ) {}

    public function run(array $command, ?string $cwd = null): int
    {
        $this->calls[] = ['command' => $command, 'cwd' => $cwd];

        if ($this->onRun !== null) {
            ($this->onRun)($command, $cwd);
        }

        return $this->exitCode;
    }
}
