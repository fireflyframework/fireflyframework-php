<?php

declare(strict_types=1);

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Security\OAuth2\Client\Authorized\OAuth2AuthorizedClient;
use Firefly\Security\OAuth2\Client\Authorized\OAuth2AuthorizedClientManager;
use Firefly\Security\OAuth2\Client\Http\OAuth2ClientHttpMacros;
use Firefly\Security\OAuth2\Client\Token\OAuth2AccessToken;
use Firefly\Testing\FireflyTestCase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(FireflyTestCase::class);

it('registers Http::oauth2Client(), which asks the manager for the registration and attaches its bearer', function () {
    /** @var FireflyTestCase $this */
    HttpFactory::flushMacros();
    OAuth2ClientHttpMacros::register($this->app());

    expect(HttpFactory::hasMacro('oauth2Client'))->toBeTrue()
        ->and(fn () => Http::oauth2Client('svc'))->toThrow(ConfigurationException::class, 'firefly.security.oauth2.client.enabled');

    $manager = new class implements OAuth2AuthorizedClientManager
    {
        /** @var list<array{0: string, 1: ?string}> */
        public array $asked = [];

        public function authorize(string $clientRegistrationId, ?string $principalName = null): OAuth2AuthorizedClient
        {
            $this->asked[] = [$clientRegistrationId, $principalName];

            return new OAuth2AuthorizedClient($clientRegistrationId, $principalName ?? 'app', new OAuth2AccessToken('bearer-'.$clientRegistrationId, 1, null));
        }
    };
    $this->app()->instance(OAuth2AuthorizedClientManager::class, $manager);
    Http::fake();

    Http::oauth2Client('svc')->get('https://api.example.test/orders');
    Http::oauth2Client('user-api', 'ada')->post('https://api.example.test/orders', ['sku' => 'A-1']);

    Http::assertSent(static fn (Request $request): bool => $request->method() === 'GET' && $request->url() === 'https://api.example.test/orders' && $request->hasHeader('Authorization', 'Bearer bearer-svc'));
    Http::assertSent(static fn (Request $request): bool => $request->method() === 'POST' && $request->hasHeader('Authorization', 'Bearer bearer-user-api') && $request['sku'] === 'A-1');
    expect($manager->asked)->toBe([['svc', null], ['user-api', 'ada']]);
});
