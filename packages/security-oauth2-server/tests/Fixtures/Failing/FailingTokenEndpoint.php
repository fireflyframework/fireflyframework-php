<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Tests\Fixtures\Failing;

use Firefly\Security\OAuth2\Server\Web\OAuth2Endpoint;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * A machine endpoint that fails the way a driver does — with a message that carries what the request held. A
 * QueryException interpolates the bound values into the SQL it reports, and on the token endpoint those values
 * are codes and token hashes; SECRET stands in for them, so the filter's log line can be asserted to omit it.
 */
final class FailingTokenEndpoint implements OAuth2Endpoint
{
    public const string SECRET = 'the-code-value-a-driver-would-interpolate';

    public function methods(): array
    {
        return ['POST'];
    }

    public function answersJson(): bool
    {
        return true;
    }

    public function handle(Request $request): Response
    {
        throw new RuntimeException('insert into oauth2_authorizations failed (SQL: values (\''.self::SECRET.'\'))');
    }
}
