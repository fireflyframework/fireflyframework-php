<?php

declare(strict_types=1);

namespace Firefly\Eda\Postgres\Console;

use Firefly\Eda\Database\Console\OutboxRelayCommand as DatabaseOutboxRelayCommand;

/** @deprecated since W1 — use Firefly\Eda\Database\Console\OutboxRelayCommand (same signature, either provider). */
final class OutboxRelayCommand extends DatabaseOutboxRelayCommand {}
