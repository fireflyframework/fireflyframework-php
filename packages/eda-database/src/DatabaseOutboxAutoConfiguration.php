<?php

declare(strict_types=1);

namespace Firefly\Eda\Database;

use Firefly\Container\Attributes\Configuration;
use Firefly\Container\Attributes\Order;

/**
 * Wires the portable same-transaction outbox when firefly.eda.provider=database. #[Order(900)] — below
 * DataAutoConfiguration's / CqrsAutoConfiguration's / EdaAutoConfiguration's 1000 — so its beans register first and
 * the framework defaults behind #[ConditionalOnMissingBean] back off. The beans arrive with the classes they build.
 */
#[Configuration]
#[Order(900)]
final class DatabaseOutboxAutoConfiguration {}
