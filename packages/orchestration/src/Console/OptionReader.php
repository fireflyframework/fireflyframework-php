<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Console;

/**
 * Typed reads of command options and arguments (Symfony hands them back as mixed).
 *
 * @internal
 */
trait OptionReader
{
    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
