<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\Support;

use Firefly\Orchestration\Saga\Builder\SagaBuilder;
use Firefly\Orchestration\Tcc\Builder\TccBuilder;
use Symfony\Component\Process\Process;

/**
 * Two executions that die with their process. With $crash, `crash-saga` exits inside step `charge` (after
 * `reserve` was checkpointed DONE and `charge` RUNNING) and `crash-tcc` exits inside the `credit` CONFIRM
 * (after `debit` was CONFIRMED). exit() is not an exception: no catch, no compensation, no final checkpoint —
 * exactly what an OOM kill or a deploy leaves behind. Every step appends a line to the marks file, so the
 * recovering process can prove what ran where.
 */
final class CrashScenarios
{
    public static function register(TestEngines $engines, string $marks, bool $crash): void
    {
        $mark = static function (string $line) use ($marks): void {
            file_put_contents($marks, $line."\n", FILE_APPEND);
        };

        $engines->sagaRegistry->register(SagaBuilder::saga('crash-saga')
            ->step('reserve')->handler(static function () use ($mark): array {
                $mark('reserve');

                return ['hold' => 'h-1'];
            })->compensation(static function () use ($mark): void {
                $mark('release');
            })->add()
            ->step('charge')->dependsOn('reserve')->handler(static function () use ($mark, $crash): string {
                if ($crash) {
                    exit(70);
                }
                $mark('charge');

                return 'paid';
            })->add()
            ->build());

        $engines->tccRegistry->register(TccBuilder::tcc('crash-tcc')->retry(false)
            ->participant('debit')->order(1)
            ->tryWith(static function () use ($mark): string {
                $mark('try debit');

                return 'd';
            })
            ->confirmWith(static function () use ($mark): void {
                $mark('confirm debit');
            })
            ->cancelWith(static function () use ($mark): void {
                $mark('cancel debit');
            })
            ->add()
            ->participant('credit')->order(2)
            ->tryWith(static function () use ($mark): string {
                $mark('try credit');

                return 'c';
            })
            ->confirmWith(static function () use ($mark, $crash): void {
                if ($crash) {
                    exit(70);
                }
                $mark('confirm credit');
            })
            ->cancelWith(static function () use ($mark): void {
                $mark('cancel credit');
            })
            ->add()
            ->build());
    }

    public static function start(TestEngines $engines, string $scenario, string $correlationId): void
    {
        if ($scenario === 'tcc') {
            $engines->tccs->execute('crash-tcc', [], [], $correlationId);

            return;
        }

        $engines->sagas->execute('crash-saga', ['orderId' => 1], [], $correlationId);
    }

    /** Runs the scenario in a child PHP process that dies inside a step; returns its exit code (70). */
    public static function crash(string $target, string $scenario, string $correlationId, string $marks): int
    {
        $process = new Process([PHP_BINARY, __DIR__.'/crash-orchestration.php', $target, $scenario, $correlationId, $marks], timeout: 60);
        $process->run();

        return (int) $process->getExitCode();
    }

    /** @return list<string> */
    public static function marks(string $file): array
    {
        return is_file($file) ? array_values(array_filter(explode("\n", (string) file_get_contents($file)), static fn (string $line): bool => $line !== '')) : [];
    }
}
