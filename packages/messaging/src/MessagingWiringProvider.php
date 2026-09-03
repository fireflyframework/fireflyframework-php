<?php

declare(strict_types=1);

namespace Firefly\Messaging;

use Firefly\Context\Boot\BootPass;
use Firefly\Context\Boot\FireflyServiceProvider;
use Firefly\Context\Scan\AppScan;
use Firefly\Messaging\Boot\MessageListenerWiringPass;
use Firefly\Messaging\Listener\MessageListenerManifest;
use Firefly\Messaging\Scanner\MessageListenerScanner;
use Illuminate\Contracts\Container\Container;

/**
 * The boot-pass half of firefly/messaging (MessagingServiceProvider extends AutoConfiguration and cannot consume
 * passes()). Contributes MessageListenerWiringPass via passes() and resolves the MessageListenerManifest behind a
 * bound() guard — compiled artifact first, then an in-process scan of firefly.scan.paths, then empty. Both this
 * and MessagingServiceProvider are listed in extra.laravel.providers. Mirrors EdaWiringProvider /
 * SchedulingWiringProvider.
 */
final class MessagingWiringProvider extends FireflyServiceProvider
{
    public function register(): void
    {
        if (! $this->app->bound(MessageListenerManifest::class)) {
            $this->app->singleton(MessageListenerManifest::class, static function (Container $app): MessageListenerManifest {
                if (($file = AppScan::cachedFile($app, AppScan::MESSAGE_LISTENERS)) !== null) {
                    return MessageListenerManifest::load($file);
                }

                $paths = AppScan::paths($app);

                return new MessageListenerManifest($paths === [] ? [] : (new MessageListenerScanner)->scan($paths));
            });
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
