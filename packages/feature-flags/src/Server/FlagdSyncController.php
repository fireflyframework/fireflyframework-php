<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Server;

use Firefly\FeatureFlags\FeatureFlagsSettings;
use Firefly\FeatureFlags\Registry\FlagRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Serves the shared flagd document, authenticating before conditional-response handling. */
final class FlagdSyncController
{
    public function __construct(
        private readonly FlagRegistry $registry,
        private readonly FeatureFlagsSettings $settings,
    ) {}

    public function __invoke(Request $request): Response
    {
        $token = $this->settings->server->token;
        if ($token !== '' && ! hash_equals($token, (string) $request->bearerToken())) {
            return new Response('', 401, ['WWW-Authenticate' => 'Bearer realm="feature-flags"', 'Cache-Control' => 'no-cache']);
        }

        $body = $this->registry->composition(false)->document()->toJson();
        $etag = '"'.hash('sha256', $body).'"';
        $headers = ['ETag' => $etag, 'Cache-Control' => 'no-cache'];

        $match = array_map(static function (string $value): string {
            $value = trim($value);

            return str_starts_with($value, 'W/') ? substr($value, 2) : $value;
        }, explode(',', (string) $request->headers->get('If-None-Match', '')));
        if (in_array($etag, $match, true) || in_array('*', $match, true)) {
            return new Response('', 304, $headers);
        }

        return new Response($body, 200, ['Content-Type' => 'application/json'] + $headers);
    }
}
