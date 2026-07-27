<?php

declare(strict_types=1);

namespace Firefly\Cli\Tests\Fixtures\App;

use Firefly\Container\Attributes\Service;
use Firefly\Data\Transaction\Attributes\Transactional;

/**
 * A #[Service] with a #[Transactional] method — firefly:cache emits a proxy subclass for it, so this class is
 * deliberately NOT final (the generated proxy `extends` it).
 */
#[Service]
class DemoTransactionalService
{
    #[Transactional]
    public function save(string $id): string
    {
        return 'saved:'.$id;
    }
}
