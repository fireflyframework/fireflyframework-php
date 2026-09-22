<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\LogoutContext;

use Firefly\Security\Core\SecurityContext;
use Firefly\Security\Session\SecurityContextRepository;
use Firefly\Security\Session\SessionSecurityContextRepository;
use Illuminate\Http\Request;

/**
 * A bound SecurityContextRepository of an application's own, for the flows that hold LogoutHandler to the PORT
 * rather than to the session. It delegates every call to the shipped session store — so login, the persistence
 * filter and the rest of the chain behave exactly as they do without it — and counts the clear() calls, which
 * is the whole of what a custom store ever hears from signing out.
 *
 * ONE SHARED INSTANCE, because the override seam is a competing bean DEFINITION (see the sibling
 * #[Configuration]) and a bean is built by the container, not handed to it: the test reads the same object the
 * boot resolved through `shared()`, and `reset()` puts the count back to zero between cases.
 */
final class CountingSecurityContextRepository implements SecurityContextRepository
{
    private static ?self $shared = null;

    public int $cleared = 0;

    private SessionSecurityContextRepository $delegate;

    public function __construct()
    {
        $this->delegate = new SessionSecurityContextRepository;
    }

    public static function shared(): self
    {
        return self::$shared ??= new self;
    }

    public static function reset(): void
    {
        self::shared()->cleared = 0;
    }

    public function load(Request $request): ?SecurityContext
    {
        return $this->delegate->load($request);
    }

    public function save(SecurityContext $context, Request $request): void
    {
        $this->delegate->save($context, $request);
    }

    public function clear(Request $request): void
    {
        $this->cleared++;
        $this->delegate->clear($request);
    }
}
