<?php

declare(strict_types=1);

namespace Firefly\Security\Web\EntryPoint;

use Firefly\Kernel\Exception\Security\AuthenticationException;
use Firefly\Security\Web\Settings\HttpBasicSettings;
use Firefly\Web\Error\ErrorPageRenderer;
use Firefly\Web\Exception\ProblemDetailsRenderer;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The 401 with a `WWW-Authenticate: Basic realm="…"` header (RFC 7617; Spring's BasicAuthenticationEntryPoint).
 * The body is whatever the framework would have rendered for the exception — the HTML page for a browser,
 * problem+json otherwise — so a client that ignores the header still reads the same document it always did.
 */
final class BasicAuthenticationEntryPoint implements AuthenticationEntryPoint
{
    public function __construct(
        private readonly HttpBasicSettings $settings,
        private readonly ErrorPageRenderer $pages,
        private readonly ProblemDetailsRenderer $problems,
    ) {}

    public function commence(Request $request, AuthenticationException $exception): Response
    {
        $response = $this->pages->handles($request)
            ? $this->pages->render($exception, $request)
            : $this->problems->render($exception, $request);

        $response->headers->set('WWW-Authenticate', $this->settings->challenge());

        return $response;
    }
}
