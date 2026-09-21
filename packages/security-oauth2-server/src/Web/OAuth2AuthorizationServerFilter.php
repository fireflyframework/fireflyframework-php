<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Web;

use Closure;
use Firefly\Config\Config;
use Firefly\Container\Attributes\Component;
use Firefly\Container\Attributes\Order;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Firefly\Security\OAuth2\Server\Error\OAuth2AuthenticationException;
use Firefly\Security\OAuth2\Server\Error\OAuth2Error;
use Firefly\Security\OAuth2\Server\Error\OAuth2ErrorCodes;
use Firefly\Security\OAuth2\Server\Error\OAuth2ErrorResponse;
use Firefly\Web\Filter\OncePerRequestFilter;
use Illuminate\Http\Request;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Every endpoint of the authorization server, answered from ONE filter at -82: after the session (-94), the
 * bearer filters (-90/-85) and remember-me (-83) established whatever principal there is — the authorization
 * endpoint needs it — and BEFORE CsrfFilter (-80) and HttpSecurityFilter (-70), so a machine's POST to the token
 * endpoint is never asked for a session token and no deny-by-default rule can refuse an endpoint (Spring places
 * its authorization-server filters before the authorization filter for the same reason). The consent POST, the
 * one browser form here, is checked against the session token by the endpoint itself.
 *
 * A request at none of the addresses costs one path comparison per endpoint and passes through. A wrong method
 * is 405 + Allow + the RFC 6749 document. A machine endpoint's OAuth2AuthenticationException becomes that
 * document with its status; anything else it throws is logged at ERROR — the endpoint's class, the exception's
 * class and the file:line it was thrown from, and NOT its message or the exception object: a QueryException's
 * message interpolates the bound values into the statement, and on these endpoints the bound values are codes,
 * token hashes and assertions, so the message is the one thing that must never reach the log. The exception is
 * answered here, never rethrown, so firefly/web's handler does not see it either; an endpoint that wants the
 * detail reported catches and reports it itself. The client gets `500 server_error` — a browser opening a token
 * URL never sees an HTML page here. Browser endpoints render their own refusals and let a genuine failure reach
 * firefly/web's error page like any other page. Both gates are re-read live, so a test's withoutSecurity()
 * disarms this filter too.
 */
#[Component]
#[Order(-82)]
#[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
#[ConditionalOnProperty(name: 'firefly.security.oauth2.server.enabled', havingValue: 'true')]
final class OAuth2AuthorizationServerFilter extends OncePerRequestFilter
{
    public function __construct(
        private readonly OAuth2Endpoints $endpoints,
        private readonly Config $config,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function shouldNotFilter(Request $request): bool
    {
        if (! $this->config->bool('firefly.security.enabled', false) || ! $this->config->bool('firefly.security.oauth2.server.enabled', false)) {
            return true;
        }

        return parent::shouldNotFilter($request);
    }

    protected function doFilter(Request $request, Closure $next): mixed
    {
        $endpoint = $this->endpoints->match($request);
        if ($endpoint === null) {
            return $next($request);
        }

        if (! in_array($request->getMethod(), $endpoint->methods(), true)) {
            return OAuth2ErrorResponse::json(
                new OAuth2Error(OAuth2ErrorCodes::INVALID_REQUEST, 'This endpoint accepts '.implode(', ', $endpoint->methods()).' only.'),
                405,
                ['Allow' => implode(', ', $endpoint->methods())],
            );
        }

        if (! $endpoint->answersJson()) {
            return $endpoint->handle($request);
        }

        try {
            return $endpoint->handle($request);
        } catch (OAuth2AuthenticationException $e) {
            return OAuth2ErrorResponse::fromException($e);
        } catch (Throwable $e) {
            $this->logger?->error('OAuth2 endpoint '.$endpoint::class.' failed: '.$e::class.' at '.$e->getFile().':'.$e->getLine());

            return OAuth2ErrorResponse::json(new OAuth2Error(OAuth2ErrorCodes::SERVER_ERROR, 'The authorization server could not process the request.'), 500);
        }
    }
}
