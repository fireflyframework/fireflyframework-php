<?php

declare(strict_types=1);

namespace Firefly\Webhooks;

use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;

/** The boot-pass half of firefly/webhooks: the endpoint registrar, the receipts migration and the prune command. */
final class WebhooksWiringProvider extends FireflyServiceProvider
{
    /**
     * @return list<BootPass>
     */
    public function passes(): array
    {
        return [];
    }
}
