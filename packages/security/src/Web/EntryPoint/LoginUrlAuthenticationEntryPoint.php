<?php

declare(strict_types=1);

namespace Firefly\Security\Web\EntryPoint;

use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Security\Session\SavedRequest;
use Firefly\Security\Web\Settings\FormLoginSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends the browser to the login page (Spring's LoginUrlAuthenticationEntryPoint), remembering a GET's URL
 * in the session so the successful login can come back to it. A POST is not saved: replaying a body after a
 * sign-in is never what anyone meant.
 */
final class LoginUrlAuthenticationEntryPoint implements AuthenticationEntryPoint
{
    public function __construct(private readonly FormLoginSettings $settings) {}

    public function commence(Request $request, AuthenticationException $exception): Response
    {
        if ($request->isMethod('GET') && $request->hasSession()) {
            SavedRequest::store($request->session(), $request);
        }

        return new RedirectResponse($request->getUriForPath($this->settings->loginPage));
    }
}
