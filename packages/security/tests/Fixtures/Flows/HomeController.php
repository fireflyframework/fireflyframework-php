<?php

declare(strict_types=1);

namespace Firefly\Security\Tests\Fixtures\Flows;

use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Web\Attributes\Controller;
use Firefly\Web\Attributes\GetMapping;
use Illuminate\Http\Response;

/** A protected HTML page: what a browser asks for after signing in. */
#[Controller]
final class HomeController
{
    #[GetMapping('/home')]
    public function home(): Response
    {
        $name = SecurityContextHolder::getAuthentication()?->getName() ?? 'nobody';

        return new Response('<!DOCTYPE html><html><body><h1>Home</h1><p>Signed in as '.htmlspecialchars($name).'</p></body></html>', 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }
}
