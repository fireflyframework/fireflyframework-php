<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Authorization\InMemoryOAuth2AuthorizationService;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2Authorization;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationPurgeTask;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2Token;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2TokenType;
use Firefly\Security\OAuth2\Server\Client\AuthorizationGrantType;
use Firefly\Security\OAuth2\Server\Client\RegisteredClientFactory;
use Firefly\Security\OAuth2\Server\Settings\AuthorizationServerSettings;
use Firefly\Security\Tests\Support\RecordingLogger;

it('removes every authorization whose last token expired and logs the count', function () {
    $service = new InMemoryOAuth2AuthorizationService;
    $client = RegisteredClientFactory::fromConfig('svc', ['client_secret' => '{noop}s', 'authorization_grant_types' => ['client_credentials']], new AuthorizationServerSettings);
    $expired = OAuth2Authorization::create($client, 'svc', AuthorizationGrantType::ClientCredentials, [])
        ->withToken(OAuth2Token::issue(OAuth2TokenType::AccessToken, 'old', new DateTimeImmutable('-2 hours'), new DateTimeImmutable('-1 hour')));
    $live = OAuth2Authorization::create($client, 'svc', AuthorizationGrantType::ClientCredentials, [])
        ->withToken(OAuth2Token::issue(OAuth2TokenType::AccessToken, 'new', new DateTimeImmutable, new DateTimeImmutable('+1 hour')));
    $service->save($expired);
    $service->save($live);
    $logger = new RecordingLogger;

    expect((new OAuth2AuthorizationPurgeTask($service, $logger))->purge())->toBe(1)
        ->and($service->findById($live->id))->not->toBeNull()
        ->and($logger->records[0]['message'] ?? '')->toContain('1 expired');
});
