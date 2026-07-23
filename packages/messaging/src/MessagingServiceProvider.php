<?php

declare(strict_types=1);

namespace Firefly\Messaging;

use Firefly\AutoConfigure\AutoConfiguration;

/**
 * The discovered messaging auto-configuration provider (extra.laravel.providers). Extending AutoConfiguration means
 * its final register() records candidacy ONLY. In Task 11 the two compiled manifests it points at are EMPTY
 * (return []); Task 16 introduces MessagingAutoConfiguration and regenerates them. The boot-pass half rides on the
 * SEPARATE MessagingWiringProvider (Task 16).
 */
final class MessagingServiceProvider extends AutoConfiguration
{
    protected function componentManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-messaging-components.php';
    }

    protected function contextManifestPath(): string
    {
        return __DIR__.'/../cache/firefly-messaging-context.php';
    }
}
