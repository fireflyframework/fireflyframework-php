<?php

declare(strict_types=1);

namespace Firefly\Security\Web\EntryPoint;

use Firefly\Kernel\Exception\Security\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The behaviour every protected URL had before entry points existed: the 401 flows to Laravel's exception
 * handler, where firefly/web renders the HTML page for a browser and problem+json for everyone else.
 */
final class ProblemAuthenticationEntryPoint implements AuthenticationEntryPoint
{
    public function commence(Request $request, AuthenticationException $exception): Response
    {
        throw $exception;
    }
}
