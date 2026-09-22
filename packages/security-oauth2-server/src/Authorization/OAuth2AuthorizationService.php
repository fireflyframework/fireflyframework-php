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
 *
 * processLocal() is on the INTERFACE for the reason HttpExchangeRecorder declares its own storage()/processLocal()
 * pair: the surface that prints a count has to be able to say what the count MEANS, and only the store knows.
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

    /**
     * True when the authorizations live only in the CURRENT PHP process — when a reader in another process
     * (every reader, under php-fpm) counts none of what this one saved.
     *
     * countActiveForClient() is published per client by /actuator/oauth2clients and rendered in the dashboard's
     * Active column, and a number that is silently this worker's alone is not a smaller truth but the opposite
     * one: on a per-process store every client reads 0 in the process that renders the page while the workers
     * beside it hold hundreds, and that 0 is read exactly when an operator is asking why a client "cannot get a
     * token". So the store says which kind it is, and the surfaces print the caveat rather than infer it.
     *
     * ASKED OF THE PORT, NEVER GUESSED FROM THE IMPLEMENTATION. The bean carries #[ConditionalOnMissingBean] and
     * `authorizations.driver` therefore names nothing once an application binds a service of its own, so a
     * driver-keyed flag would warn it about a store it does not use — and an `instanceof` test against the
     * shipped memory class is no better in the other direction: an APCu or static-map store, a test double or a
     * decorator is process-local without being that class, and would be published as durable. Only the store
     * knows, so only the store answers.
     */
    public function processLocal(): bool;
}
