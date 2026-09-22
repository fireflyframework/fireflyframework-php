<?php

declare(strict_types=1);

namespace Firefly\Security\Web\EntryPoint;

use Firefly\Kernel\Exception\Security\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What an UNAUTHENTICATED request to a protected URL gets (Spring's AuthenticationEntryPoint): a redirect to a
 * login page, a 401 challenge, or the 401 problem document. HttpSecurityFilter calls it in place of throwing;
 * an implementation may still throw the exception to let the ordinary exception rendering answer.
 */
interface AuthenticationEntryPoint
{
    /**
     * @throws AuthenticationException
     */
    public function commence(Request $request, AuthenticationException $exception): Response;
}
