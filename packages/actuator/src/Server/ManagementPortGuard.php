<?php

declare(strict_types=1);

namespace Firefly\Actuator\Server;

use Illuminate\Http\Request;

/**
 * The request-time half of `firefly.management.server.port`: does THIS request qualify to reach the management
 * surface? A bound singleton, and the PUBLIC SEAM any other package mounting management UI reads — firefly/admin
 * resolves this class and calls permits() on its dashboard request so the dashboard obeys the same port boundary as
 * the JSON endpoints. Keep permits() a pure predicate for exactly that reason: callers render their own 404 (the
 * actuator renders RFC-9457 problem+json, the dashboard renders HTML), and a guard that returned a Response would
 * force one of them to fake the other's content type.
 *
 * WITHOUT A CONFIGURED PORT THIS IS A NO-OP. permits() returns true unconditionally, so an application that never
 * heard of a management port behaves byte-for-byte as it did before this class existed — no new 404s, no new header
 * reads, no new failure mode.
 *
 * WHICH "PORT THE REQUEST ARRIVED ON" — this is the whole security argument, so it is spelled out.
 *
 * The obvious call is `Request::getPort()`. It is the WRONG one. With no trusted proxy configured, Symfony derives
 * that from the HOST HEADER, which the client writes: `curl -H 'Host: localhost:9001' http://localhost:8000/actuator/env`
 * would walk straight through a guard built on it. A boundary a client can talk its way past is not a boundary.
 *
 * So the guard reads `SERVER_PORT` — written by the SAPI from the socket that actually accepted the connection
 * (php-fpm from the pool's `listen`, the built-in server from `-S host:port`), never from request bytes. That is
 * exactly the fact being asserted: this request came in on the management listener.
 *
 * `X-Forwarded-Port` is honoured ONLY when the request comes from a trusted proxy (Laravel's TrustProxies
 * middleware, i.e. an address the application has explicitly vouched for). That covers the real deployment where one
 * nginx/ALB terminates both :8000 and :9001 and forwards both to the SAME upstream pool, where SERVER_PORT is
 * identical for both and the forwarded header is the only remaining evidence. Untrusted, the header is ignored
 * outright rather than merged in — an attacker-supplied header is not a weaker signal, it is not a signal.
 */
final readonly class ManagementPortGuard
{
    public function __construct(private ManagementServerSettings $settings) {}

    /**
     * True when the actuator may answer this request. Callers 404 on false — never 403: a 403 would confirm that a
     * management surface exists on some other port, which is one more fact than an unauthenticated scan of the
     * public port deserves, and 404 is what the actuator already returns for an unexposed endpoint.
     */
    public function permits(Request $request): bool
    {
        if ($this->settings->port === null) {
            return true;
        }

        return $this->arrivalPort($request) === $this->settings->port;
    }

    /**
     * X-Forwarded-Port is evidence only when BOTH halves of Symfony's trusted-proxy contract hold: the peer is a
     * trusted proxy, AND the application actually opted into that header (`Request::setTrustedProxies()`'s header
     * set — Laravel's TrustProxies `$headers`). An operator who trusts a proxy for X-Forwarded-For alone has said
     * their proxy does not sanitise the port header, and Symfony's own getPort() ignores it in that state; honouring
     * it here would let a client behind such a proxy name its own arrival port and walk into the actuator.
     */
    private function trustsForwardedPort(Request $request): bool
    {
        return $request->isFromTrustedProxy()
            && (Request::getTrustedHeaderSet() & Request::HEADER_X_FORWARDED_PORT) !== 0;
    }

    /**
     * The port the listener accepted on, or null when it cannot be established (a SAPI that sets no SERVER_PORT).
     * Null never permits a guarded request: an unknown port is not the management port.
     */
    public function arrivalPort(Request $request): ?int
    {
        $forwarded = $this->trustsForwardedPort($request)
            ? $request->headers->get('X-Forwarded-Port')
            : null;

        // A forwarded chain is comma-separated and outermost-first; the first hop is the one that terminated the
        // port the client actually dialled, which is the port the operator's rule names.
        $candidate = $forwarded !== null && $forwarded !== ''
            ? explode(',', $forwarded)[0]
            : $request->server->get('SERVER_PORT');

        if (is_int($candidate)) {
            return $candidate;
        }

        return is_string($candidate) && preg_match('/^\d+$/', trim($candidate)) === 1
            ? (int) trim($candidate)
            : null;
    }
}
