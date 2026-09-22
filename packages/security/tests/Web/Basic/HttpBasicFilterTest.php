<?php

declare(strict_types=1);

use Firefly\Config\Config;
use Firefly\Security\Authentication\DaoAuthenticationProvider;
use Firefly\Security\Authentication\ProviderManager;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Security\Core\SimpleGrantedAuthority;
use Firefly\Security\Event\AuthenticationEventPublisher;
use Firefly\Security\Event\AuthenticationFailureBadCredentialsEvent;
use Firefly\Security\Password\NoOpPasswordEncoder;
use Firefly\Security\Session\SessionSecurityContextRepository;
use Firefly\Security\Session\SessionSecuritySettings;
use Firefly\Security\User\InMemoryUserDetailsService;
use Firefly\Security\User\User;
use Firefly\Security\Web\Basic\HttpBasicFilter;
use Firefly\Security\Web\EntryPoint\BasicAuthenticationEntryPoint;
use Firefly\Security\Web\Settings\HttpBasicSettings;
use Firefly\Testing\Double\RecordingAuthenticationEvents;
use Firefly\Web\Error\ErrorPageRenderer;
use Firefly\Web\Error\ErrorPageSettings;
use Firefly\Web\Exception\ProblemDetailsRenderer;
use Illuminate\Config\Repository;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

uses(TestCase::class);

afterEach(fn () => SecurityContextHolder::clearContext());

/** @return array{0: HttpBasicFilter, 1: RecordingAuthenticationEvents} */
function basicFilter(): array
{
    $events = new RecordingAuthenticationEvents;
    $config = new Config(new Repository(['firefly' => ['security' => ['enabled' => true, 'http_basic' => ['enabled' => true, 'realm' => 'Ledger']]]]));
    $settings = HttpBasicSettings::fromConfig($config);
    // The NoOp encoder compares the raw string, so the stored "hash" is the password itself (no `{id}` prefix:
    // the delegating encoder is not in this unit).
    $users = new InMemoryUserDetailsService([new User('ada', 'secret', [new SimpleGrantedAuthority('ROLE_USER')])]);
    $manager = new ProviderManager([new DaoAuthenticationProvider($users, new NoOpPasswordEncoder)]);

    $filter = new HttpBasicFilter(
        $settings,
        $manager,
        new BasicAuthenticationEntryPoint($settings, new ErrorPageRenderer(new ErrorPageSettings), new ProblemDetailsRenderer(new ErrorPageSettings)),
        new SessionSecurityContextRepository,
        new SessionSecuritySettings($config),
        new AuthenticationEventPublisher($events),
        $config,
    );

    return [$filter, $events];
}

function basicRequest(?string $header): Request
{
    $request = Request::create('/api/x', 'GET', server: ['HTTP_ACCEPT' => 'application/json']);
    if ($header !== null) {
        $request->headers->set('Authorization', $header);
    }

    return $request;
}

/**
 * The filter's answer as the response it is: OncePerRequestFilter::handle() is typed mixed, and a filter that
 * answered with anything else would be a defect, not a type to paper over.
 *
 * @param  Closure(): Response  $next
 */
function basicHandle(HttpBasicFilter $filter, Request $request, Closure $next): SymfonyResponse
{
    $response = $filter->handle($request, $next);
    if (! $response instanceof SymfonyResponse) {
        throw new RuntimeException('The filter did not answer with a response.');
    }

    return $response;
}

it('parses the header, authenticates, establishes the context for the chain and clears it on exit', function () {
    [$filter, $events] = basicFilter();

    expect(HttpBasicFilter::credentials(basicRequest('Basic '.base64_encode('ada:se:cret'))))->toBe(['ada', 'se:cret'])
        ->and(HttpBasicFilter::credentials(basicRequest('Bearer x')))->toBeNull()
        ->and(HttpBasicFilter::credentials(basicRequest('Basic not*base64')))->toBeNull()
        ->and(HttpBasicFilter::credentials(basicRequest('Basic '.base64_encode('nocolon'))))->toBeNull();

    $seen = null;
    $response = basicHandle($filter, basicRequest('Basic '.base64_encode('ada:secret')), function () use (&$seen): Response {
        $seen = SecurityContextHolder::getAuthentication()?->authorityStrings();

        return new Response('ok');
    });

    expect($response->getStatusCode())->toBe(200)
        ->and($seen)->toBe(['ROLE_USER'])
        ->and(SecurityContextHolder::getContext()->isAuthenticated())->toBeFalse()
        ->and($events->interactive())->toHaveCount(1)
        ->and($events->interactive()[0]->mechanism)->toBe('basic');
});

it('answers a wrong password and a malformed header with the challenge, and publishes the failure', function () {
    [$filter, $events] = basicFilter();
    $reached = false;
    $next = function () use (&$reached): Response {
        $reached = true;

        return new Response('ok');
    };

    $wrong = basicHandle($filter, basicRequest('Basic '.base64_encode('ada:nope')), $next);
    $malformed = basicHandle($filter, basicRequest('Basic ???'), $next);

    expect($reached)->toBeFalse()
        ->and($wrong->getStatusCode())->toBe(401)
        ->and($wrong->headers->get('WWW-Authenticate'))->toBe('Basic realm="Ledger", charset="UTF-8"')
        ->and((string) $wrong->getContent())->toContain('"code":"AUTHENTICATION_FAILED"')
        ->and($malformed->getStatusCode())->toBe(401)
        ->and($events->failures())->toHaveCount(1)
        ->and($events->failures()[0])->toBeInstanceOf(AuthenticationFailureBadCredentialsEvent::class)
        ->and($events->failures()[0]->username)->toBe('ada');
});

it('is the anonymous path without a header', function () {
    [$filter] = basicFilter();

    $seen = 'unset';
    $filter->handle(basicRequest(null), function () use (&$seen): Response {
        $seen = SecurityContextHolder::getAuthentication();

        return new Response('ok');
    });

    expect($seen)->toBeNull();
});
