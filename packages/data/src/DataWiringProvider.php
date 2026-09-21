<?php

declare(strict_types=1);

namespace Firefly\Data;

use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Data\Boot\TransactionalEventListenerWiringPass;

/**
 * The boot-pass half of firefly/data. It CANNOT ride on DataServiceProvider: that extends AutoConfiguration,
 * whose final register() records candidacy ONLY and never consumes passes(). So — exactly like
 * EdaWiringProvider and ActuatorWiringProvider — this plain FireflyServiceProvider contributes the
 * TransactionalEventListenerWiringPass. It binds nothing: every data bean (the manifest, the settings, the
 * synchronization registry) is a #[Bean] of DataAutoConfiguration. Both providers are listed in
 * extra.laravel.providers; a test that boots DataServiceProvider alone simply gets no transactional listeners.
 */
final class DataWiringProvider extends FireflyServiceProvider
{
    /**
     * @return list<BootPass>
     */
    public function passes(): array
    {
        return [new TransactionalEventListenerWiringPass];
    }
}
