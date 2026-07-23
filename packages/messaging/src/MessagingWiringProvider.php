<?php

declare(strict_types=1);

namespace Firefly\Messaging;

use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Messaging\Boot\MessageListenerWiringPass;
use Firefly\Messaging\Listener\MessageListenerManifest;

/**
 * The boot-pass half of firefly/messaging (MessagingServiceProvider extends AutoConfiguration and cannot consume
 * passes()). Contributes MessageListenerWiringPass via passes() and binds a default empty MessageListenerManifest
 * behind a bound() guard so a bare skeleton still boots. Both this and MessagingServiceProvider are listed in
 * extra.laravel.providers. Mirrors EdaWiringProvider / SchedulingWiringProvider.
 */
final class MessagingWiringProvider extends FireflyServiceProvider
{
    public function register(): void
    {
        if (! $this->app->bound(MessageListenerManifest::class)) {
            $this->app->singleton(MessageListenerManifest::class, static fn (): MessageListenerManifest => new MessageListenerManifest([]));
        }

        parent::register();
    }

    /**
     * @return list<BootPass>
     */
    public function passes(): array
    {
        return [new MessageListenerWiringPass];
    }
}
