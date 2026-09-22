<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\OwnLogin;

use Firefly\Web\Attributes\Controller;
use Firefly\Web\Attributes\GetMapping;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * An application that ships its OWN sign-in page at the configured login_page — the Breeze/Fortify shape, or
 * any #[GetMapping('/login')] — and turns form login on for the POST. Its form posts the session token the
 * way the framework page does, so a flow can sign in through it.
 */
#[Controller]
final class OwnLoginController
{
    #[GetMapping('/login')]
    public function page(Request $request): Response
    {
        $token = $request->hasSession() ? $request->session()->token() : '';

        return new Response(
            '<!DOCTYPE html><html><body><h1>Our own sign-in</h1>'
            .'<form method="post" action="/login"><input type="hidden" name="_token" value="'.htmlspecialchars($token).'">'
            .'<input name="username"><input name="password" type="password"><button>Go</button></form></body></html>',
            200,
            ['Content-Type' => 'text/html; charset=UTF-8'],
        );
    }
}
