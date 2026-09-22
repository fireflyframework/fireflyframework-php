<?php

declare(strict_types=1);

namespace Firefly\Security\Session;

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;

/**
 * The URL a browser was refused at, kept in the session so a successful login can send it back there
 * (Spring's SavedRequest, reduced to the one thing a GET needs). Only the full URL is kept: replaying a POST
 * body after a login is a surprise nobody wants, and the entry point stores GET requests only.
 */
final class SavedRequest
{
    public const string KEY = 'firefly.security.saved_request';

    public static function store(Session $session, Request $request): void
    {
        $session->put(self::KEY, $request->fullUrl());
    }

    /** The saved URL, removed from the session as it is read. */
    public static function consume(Session $session): ?string
    {
        /** @var mixed $url */
        $url = $session->pull(self::KEY);

        return is_string($url) && $url !== '' ? $url : null;
    }
}
