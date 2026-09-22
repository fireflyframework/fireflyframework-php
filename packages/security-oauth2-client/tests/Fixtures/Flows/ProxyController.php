<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Client\Tests\Fixtures\Flows;

use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\RestController;
use Illuminate\Support\Facades\Http;

/** An application calling an API with the signed-in person's token, and with the application's own (client credentials). */
#[RestController]
final class ProxyController
{
    private const string USER_INFO = 'http://localhost/fake-idp/userinfo';

    /** @return array<string, mixed> */
    #[GetMapping('/api/proxy/me')]
    public function me(): array
    {
        /** @var array<string, mixed> $json */
        $json = (array) Http::oauth2Client('fake')->get(self::USER_INFO)->json();

        return $json;
    }

    /** @return array<string, mixed> */
    #[GetMapping('/api/proxy/service')]
    public function service(): array
    {
        /** @var array<string, mixed> $json */
        $json = (array) Http::oauth2Client('svc')->get(self::USER_INFO)->json();

        return $json;
    }
}
