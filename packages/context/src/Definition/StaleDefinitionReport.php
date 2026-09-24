<?php

declare(strict_types=1);

namespace Firefly\Context\Definition;

/**
 * The classes a repair boot dropped from the definition registry because the compiled manifest named
 * them and autoloading could not find them.
 *
 * It exists because skipping in silence is its own small version of the problem the skip was written to
 * solve. A developer who deletes a #[Component] and runs `php artisan firefly:cache` gets a boot whose
 * wiring deliberately DIFFERS from the manifest on disk, and until this record existed there was no line
 * anywhere naming what the difference was: the command printed "wrote N manifest(s)" exactly as it does
 * on a clean run, so nothing distinguished "your cache was stale and I dropped an entry" from "your cache
 * was fine".
 *
 * Shaped like ConditionEvaluationReport on purpose — a plain, append-only record filled during boot and
 * read afterwards by whoever wants to report it (firefly/cli's CacheCommand today) — because the
 * alternative is a logger edge from the boot engine outward, and neither firefly/context nor
 * firefly/container requires psr/log. A report the container carries costs no dependency at all.
 *
 * Deduplicated and insertion-ordered: the same class can reach the registry from more than one source
 * (a user definition and an auto-configuration naming it), and a developer reading the command's output
 * wants the list of files to stop worrying about, not a tally of registry writes.
 */
final class StaleDefinitionReport
{
    /** @var list<string> */
    private array $classes = [];

    public function record(string $class): void
    {
        if (in_array($class, $this->classes, true)) {
            return;
        }

        $this->classes[] = $class;
    }

    /** @return list<string> */
    public function classes(): array
    {
        return $this->classes;
    }

    public function isEmpty(): bool
    {
        return $this->classes === [];
    }
}
