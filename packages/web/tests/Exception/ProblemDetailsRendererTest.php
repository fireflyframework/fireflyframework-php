<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Business\ResourceNotFoundException;
use Firefly\Web\Exception\ProblemDetailsRenderer;
use Illuminate\Http\Request;

it('renders a FireflyException as 404 application/problem+json', function () {
    $response = (new ProblemDetailsRenderer)->render(
        new ResourceNotFoundException('Account 42 not found'),
        Request::create('/accounts/42', 'GET'),
    );

    expect($response->getStatusCode())->toBe(404)
        ->and($response->headers->get('Content-Type'))->toBe('application/problem+json');

    /** @var array<string,mixed> $payload */
    $payload = json_decode((string) $response->getContent(), true);
    expect($payload['status'])->toBe(404)
        ->and($payload['title'])->toBe('Not Found')
        ->and($payload['code'])->toBe('RESOURCE_NOT_FOUND')
        ->and($payload['category'])->toBe('business')
        ->and($payload['detail'])->toBe('Account 42 not found')
        ->and($payload['instance'])->toBe('accounts/42');
});

it('converts a generic Throwable to a 500 problem+json', function () {
    $response = (new ProblemDetailsRenderer)->render(new RuntimeException('boom'), Request::create('/x'));

    expect($response->getStatusCode())->toBe(500);
    /** @var array<string,mixed> $payload */
    $payload = json_decode((string) $response->getContent(), true);
    expect($payload['status'])->toBe(500)->and($payload['category'])->toBe('internal');
});
