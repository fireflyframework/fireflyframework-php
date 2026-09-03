<?php

declare(strict_types=1);

namespace Firefly\Cli\Cache;

use Illuminate\Contracts\Foundation\Application;

/** The ONE source of the app cache-dir convention + artifact basenames (shared by writer/clear/provider). */
final class FireflyCachePaths
{
    public const string COMPONENT = 'component.php';

    public const string CONTEXT = 'context.php';

    public const string CONFIG_PROPERTIES = 'config-properties.php';

    public const string ROUTES = 'routes.php';

    public const string EXCEPTION_HANDLERS = 'exception-handlers.php';

    public const string CONSTRAINTS = 'constraints.php';

    public const string HANDLERS = 'handlers.php';

    public const string EVENT_LISTENERS = 'event-listeners.php';

    public const string MESSAGE_LISTENERS = 'message-listeners.php';

    public const string SCHEDULED = 'scheduled.php';

    public const string SECURITY_METHODS = 'security-methods.php';

    public const string TRANSACTIONAL = 'transactional.php';

    public const string PROXY_MAP = 'proxies.php';

    public const string PROXY_DIR = 'proxies';

    public static function dir(Application $app): string
    {
        $configured = $app->make('config')->get('firefly.cache.path');

        return is_string($configured) && $configured !== ''
            ? rtrim($configured, '/')
            : $app->basePath('bootstrap/cache/firefly');
    }

    public static function file(Application $app, string $basename): string
    {
        return self::dir($app).'/'.$basename;
    }
}
