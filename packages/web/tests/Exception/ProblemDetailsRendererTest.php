<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Business\ResourceNotFoundException;
use Firefly\Web\Exception\ProblemDetailsRenderer;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

it('preserves the real status of a Symfony HttpExceptionInterface instead of forcing 500 (bug fix)', function () {
    // A URL with NO matching route at all raises Symfony's NotFoundHttpException — distinct from a Firefly
    // ResourceNotFoundException thrown by a MATCHED route's handler (covered above). Before this fix EVERY
    // non-FireflyException — including this one — was collapsed into a 500 INTERNAL_ERROR, so an unrouted URL
    // never actually 404'd for a JSON client (caught by firefly/actuator's HTTP capstone master-gate-off test).
    $response = (new ProblemDetailsRenderer)->render(
        new NotFoundHttpException('The route actuator/health could not be found.'),
        Request::create('/actuator/health'),
    );

    expect($response->getStatusCode())->toBe(404);
    /** @var array<string,mixed> $payload */
    $payload = json_decode((string) $response->getContent(), true);
    expect($payload['status'])->toBe(404)
        ->and($payload['code'])->toBe('RESOURCE_NOT_FOUND')
        ->and($payload['category'])->toBe('framework')
        ->and($payload['detail'])->toBe('The route actuator/health could not be found.');
});
