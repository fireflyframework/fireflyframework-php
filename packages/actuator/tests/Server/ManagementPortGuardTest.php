<?php

declare(strict_types=1);

use Firefly\Actuator\Server\ManagementPortGuard;
use Firefly\Actuator\Server\ManagementServerSettings;
use Illuminate\Http\Request;

function guardFor(?int $port): ManagementPortGuard
{
    return new ManagementPortGuard(new ManagementServerSettings($port, null, ''));
}

/** Request::create() writes SERVER_PORT from the URI's port, exactly as a SAPI writes it from the socket. */
function requestOnPort(int $port): Request
{
    return Request::createFromBase(Request::create("http://localhost:{$port}/actuator/health"));
}

it('permits everything when no management port is configured', function () {
    expect(guardFor(null)->permits(requestOnPort(80)))->toBeTrue()
        ->and(guardFor(null)->permits(requestOnPort(9001)))->toBeTrue();
});

it('permits a request that arrived on the management port', function () {
    expect(guardFor(9001)->permits(requestOnPort(9001)))->toBeTrue();
});

it('refuses a request that arrived on the application port', function () {
    expect(guardFor(9001)->permits(requestOnPort(8000)))->toBeFalse();
});

// THE ATTACK THIS GUARD EXISTS TO STOP. Request::getPort() derives the port from the Host header when no trusted
// proxy is configured, so a guard built on it would be walked past by a forged Host. SERVER_PORT comes from the
// socket, and the forged header must not move it.
it('ignores a forged Host header claiming the management port', function () {
    // The header is set AFTER construction on purpose: Request::create() rewrites HTTP_HOST from the URI, so a
    // forged host passed in the $server array would be quietly overwritten and the test would prove nothing.
    $request = Request::createFromBase(Request::create('http://localhost:8000/actuator/env'));
    $request->headers->set('HOST', 'localhost:9001');

    expect($request->getPort())->toBe(9001)
        ->and(guardFor(9001)->permits($request))->toBeFalse();
});

// One proxy terminating both :8000 and :9001 onto the SAME upstream pool leaves SERVER_PORT identical for both;
// X-Forwarded-Port is then the only evidence left, and it is trustworthy exactly as far as the proxy is.
it('honours X-Forwarded-Port from a trusted proxy', function () {
    $request = Request::createFromBase(Request::create(
        'http://localhost:9000/actuator/health',
        'GET',
        server: ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_PORT' => '9001'],
    ));
    $request->setTrustedProxies(['10.0.0.1'], Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT);

    try {
        expect(guardFor(9001)->permits($request))->toBeTrue()
            ->and(guardFor(9002)->permits($request))->toBeFalse();
    } finally {
        Request::setTrustedProxies([], 0);
    }
});

// Trusting a proxy for X-Forwarded-For alone is a statement that the proxy does NOT sanitise the port header —
// Symfony's own getPort() ignores it in that state, and so must this guard, or a client behind such a proxy could
// name its own arrival port.
it('ignores X-Forwarded-Port when the proxy is trusted for other headers only', function () {
    $request = Request::createFromBase(Request::create(
        'http://localhost:8000/actuator/env',
        'GET',
        server: ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_FORWARDED_PORT' => '9001'],
    ));
    $request->setTrustedProxies(['10.0.0.1'], Request::HEADER_X_FORWARDED_FOR);

    try {
        expect(guardFor(9001)->arrivalPort($request))->toBe(8000)
            ->and(guardFor(9001)->permits($request))->toBeFalse();
    } finally {
        Request::setTrustedProxies([], 0);
    }
});

it('ignores X-Forwarded-Port from an untrusted peer', function () {
    $request = Request::createFromBase(Request::create(
        'http://localhost:8000/actuator/env',
        'GET',
        server: ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_PORT' => '9001'],
    ));

    expect(guardFor(9001)->permits($request))->toBeFalse();
});

it('refuses when the arrival port cannot be established at all', function () {
    $request = Request::createFromBase(Request::create('http://localhost/actuator/health'));
    $request->server->remove('SERVER_PORT');
    $request->headers->remove('HOST');

    expect(guardFor(9001)->arrivalPort($request))->toBeNull()
        ->and(guardFor(9001)->permits($request))->toBeFalse();
});
