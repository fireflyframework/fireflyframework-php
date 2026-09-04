<?php

declare(strict_types=1);

namespace Firefly\Config\Tests\Fixtures;

use Firefly\Config\Attributes\ConfigProperties;

/**
 * A VERBATIM copy of the worked example in book/src/03-configuration.md: camelCase constructor
 * parameters bound from a `config/wallet.php` that spells its keys in snake_case. Before relaxed
 * binding landed in ReflectionConfigBinder this DTO bound NOTHING from that file — both parameters
 * silently fell through to their constructor defaults — so the book shipped an example that could
 * never work. It lives here as a fixture precisely so that regression cannot come back unnoticed.
 */
#[ConfigProperties('wallet')]
final readonly class WalletProperties
{
    public function __construct(
        public int $dailyTransferLimitMinor = 1_000_000,
        public string $defaultCurrency = 'EUR',
    ) {}
}
