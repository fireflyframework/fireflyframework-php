<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Fixtures\Foundation;

use Firefly\Container\Attributes\Service;
use Firefly\Data\Transaction\Attributes\Transactional;

/**
 * A #[Service] with a #[Transactional] writer method: firefly:cache emits a proxy subclass for it, so this class is
 * deliberately NOT final (the generated proxy `extends` it). The method persists a WidgetRecord inside the
 * framework-managed transaction and returns its id — proving the transaction COMMITTED. Modelled on the T3 App\
 * DemoTransactionalService.
 */
#[Service]
class FoundationWriter
{
    #[Transactional]
    public function store(string $status): int
    {
        $record = WidgetRecord::query()->create(['status' => $status, 'amount' => 10]);

        return (int) $record->id;
    }
}
