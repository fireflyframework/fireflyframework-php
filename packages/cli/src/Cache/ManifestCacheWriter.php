<?php

declare(strict_types=1);

namespace Firefly\Cli\Cache;

use Firefly\AutoConfigure\Assembly\AutoConfigManifestCompiler;
use Firefly\Config\Scanner\ConfigManifestCompiler;
use Firefly\Config\Scanner\ConfigPropertiesScanner;
use Firefly\Cqrs\Handler\HandlerManifestCompiler;
use Firefly\Cqrs\Scanner\HandlerScanner;
use Firefly\Data\Scanner\TransactionalScanner;
use Firefly\Data\Transaction\TransactionalManifestCompiler;
use Firefly\Eda\Listener\EventListenerManifestCompiler;
use Firefly\Eda\Scanner\EventListenerScanner;
use Firefly\Messaging\Listener\MessageListenerManifestCompiler;
use Firefly\Messaging\Scanner\MessageListenerScanner;
use Firefly\Scheduling\Scanner\ScheduledScanner;
use Firefly\Scheduling\Schedule\ScheduledManifestCompiler;
use Firefly\Security\Access\Method\SecurityMethodManifestCompiler;
use Firefly\Security\Scanner\MethodSecurityScanner;
use Firefly\Validation\Constraint\ConstraintManifestCompiler;
use Firefly\Web\Route\RouteManifestCompiler;
use Firefly\Web\Route\RouteScanner;

/**
 * Orchestrates every settled package's EXISTING scanner->compiler pair (D4 DIRECT DEPENDENCY — no new port,
 * zero edits to settled/frozen src) to emit the app's compiled manifests into $dir. Deterministic + idempotent.
 */
final class ManifestCacheWriter
{
    /**
     * @param  array<string,string>  $psr4
     */
    public function writeManifests(array $psr4, string $dir): CacheReport
    {
        if (! is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }

        $files = [];

        // container + context + autoconfigure (folded): one call emits both component + context manifests.
        (new AutoConfigManifestCompiler)->write(
            $psr4,
            $files[] = $dir.'/'.FireflyCachePaths::COMPONENT,
            $files[] = $dir.'/'.FireflyCachePaths::CONTEXT,
        );

        // config — emits config-properties.php; NOT consumed via component/context (that claim is false).
        // Bound by FireflyCacheServiceProvider via ConfigRegistrar on the CACHED path only (Task 3);
        // dev (uncached) boot still leaves #[ConfigProperties] unbound — pre-existing framework limitation.
        (new ConfigManifestCompiler)->write(
            (new ConfigPropertiesScanner)->scan($psr4),
            $files[] = $dir.'/'.FireflyCachePaths::CONFIG_PROPERTIES,
        );

        // web
        (new RouteManifestCompiler)->write(
            (new RouteScanner)->scan($psr4),
            $files[] = $dir.'/'.FireflyCachePaths::ROUTES,
        );

        // validation — compiles from an explicit class list, not a PSR-4 scan (SPECIAL).
        (new ConstraintManifestCompiler)->write(
            (new ClassEnumerator)->enumerate($psr4),
            $files[] = $dir.'/'.FireflyCachePaths::CONSTRAINTS,
        );

        // cqrs — 3-arg write (handlers + destinations + path) (SPECIAL).
        $handlers = (new HandlerScanner)->scan($psr4);
        (new HandlerManifestCompiler)->write(
            $handlers['handlers'],
            $handlers['destinations'],
            $files[] = $dir.'/'.FireflyCachePaths::HANDLERS,
        );

        // eda
        (new EventListenerManifestCompiler)->write(
            (new EventListenerScanner)->scan($psr4),
            $files[] = $dir.'/'.FireflyCachePaths::EVENT_LISTENERS,
        );

        // messaging
        (new MessageListenerManifestCompiler)->write(
            (new MessageListenerScanner)->scan($psr4),
            $files[] = $dir.'/'.FireflyCachePaths::MESSAGE_LISTENERS,
        );

        // scheduling
        (new ScheduledManifestCompiler)->write(
            (new ScheduledScanner)->scan($psr4),
            $files[] = $dir.'/'.FireflyCachePaths::SCHEDULED,
        );

        // security
        (new SecurityMethodManifestCompiler)->write(
            (new MethodSecurityScanner)->scan($psr4),
            $files[] = $dir.'/'.FireflyCachePaths::SECURITY_METHODS,
        );

        // data — transactional manifest data (proxy CLASS files added in Task 3).
        (new TransactionalManifestCompiler)->write(
            (new TransactionalScanner)->scan($psr4),
            $files[] = $dir.'/'.FireflyCachePaths::TRANSACTIONAL,
        );

        return new CacheReport($files);
    }
}
