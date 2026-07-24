<?php

declare(strict_types=1);

namespace Firefly\Security\Core;

use Illuminate\Support\Facades\Context;

/**
 * The request-scoped holder for the current SecurityContext, backed by Laravel's Context facade under a single
 * key. Context is per-request state (Laravel/Octane isolate the Context facade per request; the M4 StateResetter
 * drains scoped beans but does NOT touch the Context facade). The LOAD-BEARING guarantee against principal bleed
 * is that the auth filters clearContext() in a finally on exit — PHP always runs finally, so an exception path
 * cannot skip it. getContext() returns an anonymous zero-value when unset, so a call
 * site never has to null-check the holder itself. Static (Spring's SecurityContextHolder shape) rather than a
 * bean: it is a thread/request-local accessor, not an injected collaborator.
 */
final class SecurityContextHolder
{
    private const KEY = 'firefly.security.context';

    public static function getContext(): SecurityContext
    {
        /** @var mixed $context */
        $context = Context::get(self::KEY);

        return $context instanceof SecurityContext ? $context : SecurityContext::anonymous();
    }

    public static function setContext(SecurityContext $context): void
    {
        Context::add(self::KEY, $context);
    }

    public static function clearContext(): void
    {
        Context::forget(self::KEY);
    }

    public static function getAuthentication(): ?Authentication
    {
        return self::getContext()->getAuthentication();
    }
}
