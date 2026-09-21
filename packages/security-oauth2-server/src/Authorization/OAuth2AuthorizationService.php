<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Authorization;

use DateTimeImmutable;

/**
 * Where authorizations live (Spring's OAuth2AuthorizationService). Every lookup by token hashes the presented
 * value and searches the hashes; the implementations never hold a value. `findByToken()` with no type searches
 * every token column AND the refresh-token family, which is how a replayed refresh token is recognised: the
 * presented token is not the current one, but it is in the family, so the authorization is found — and the grant
 * that finds it that way revokes the record instead of honouring it.
 *
 * save() replaces the record with the same id; an implementation strips the token values before writing
 * (OAuth2Authorization::withoutTokenValues), so what findById() returns never carries one.
 */
interface OAuth2AuthorizationService
{
    public function save(OAuth2Authorization $authorization): void;

    public function remove(OAuth2Authorization $authorization): void;

    public function findById(string $id): ?OAuth2Authorization;

    /** The authorization holding the token whose SHA-256 matches, in the given type's slot or, with null, in any slot or the refresh family. */
    public function findByToken(string $value, ?OAuth2TokenType $type = null): ?OAuth2Authorization;

    /** How many of the client's authorizations still hold an active token — what the actuator endpoint reports per client. */
    public function countActiveForClient(string $registeredClientId, DateTimeImmutable $now): int;

    /** Delete every authorization whose last token expired before $now; the number removed. */
    public function purgeExpired(DateTimeImmutable $now): int;
}
