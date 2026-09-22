<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Tests\Fixtures\OwnAuthorizationStore;

use DateTimeImmutable;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2Authorization;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationService;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2TokenType;

/**
 * An application's OWN authorization store that is PER-PROCESS — the case a class test cannot see.
 *
 * `oauth2AuthorizationService()` carries #[ConditionalOnMissingBean], so an application may bind anything:
 * a Redis store (durable), an APCu or static-map store (this one), a decorator over the shipped memory
 * driver, or a test double. Only the first is durable, and none of them is InMemoryOAuth2AuthorizationService
 * — so `$service instanceof InMemoryOAuth2AuthorizationService` publishes every one of them as durable and the
 * dashboard prints a `0` that belongs to one worker with no caveat beside it. A static map stands in here for
 * all of them: what it keeps is beside the point, that it answers processLocal() TRUE while not being the
 * shipped class is the whole point.
 */
final class OwnProcessLocalOAuth2AuthorizationService implements OAuth2AuthorizationService
{
    /** @var array<string,OAuth2Authorization> */
    private static array $authorizations = [];

    public function save(OAuth2Authorization $authorization): void
    {
        self::$authorizations[$authorization->id] = $authorization->withoutTokenValues();
    }

    public function remove(OAuth2Authorization $authorization): void
    {
        unset(self::$authorizations[$authorization->id]);
    }

    public function findById(string $id): ?OAuth2Authorization
    {
        return self::$authorizations[$id] ?? null;
    }

    public function findByToken(string $value, ?OAuth2TokenType $type = null): ?OAuth2Authorization
    {
        return null;
    }

    public function countActiveForClient(string $registeredClientId, DateTimeImmutable $now): int
    {
        return 0;
    }

    public function purgeExpired(DateTimeImmutable $now): int
    {
        return 0;
    }

    /** A static map: the next worker starts with an empty one, and none of them ever sees this one. */
    public function processLocal(): bool
    {
        return true;
    }
}
