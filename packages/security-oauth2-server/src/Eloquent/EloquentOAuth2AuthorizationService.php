<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Eloquent;

use DateTimeImmutable;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2Authorization;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2AuthorizationService;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2Token;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2TokenType;
use Firefly\Security\OAuth2\Server\Authorization\TokenHash;
use Firefly\Security\OAuth2\Server\Client\AuthorizationGrantType;

/**
 * The `eloquent` authorization driver: OAuth2Authorization ↔ oauth2_authorizations. Values are stripped before
 * the row is built (withoutTokenValues), every hash lands in its own indexed column, the family in
 * `refresh_token_family`, and `expires_at` is the latest token expiry the purge deletes by.
 */
final class EloquentOAuth2AuthorizationService implements OAuth2AuthorizationService
{
    public function __construct(private readonly OAuth2AuthorizationModelRepository $models) {}

    public function save(OAuth2Authorization $authorization): void
    {
        $existing = $this->models->findById($authorization->id);
        $model = $existing instanceof OAuth2AuthorizationModel ? $existing : new OAuth2AuthorizationModel;
        $model->fill(self::row($authorization->withoutTokenValues()));
        $this->models->save($model);
    }

    public function remove(OAuth2Authorization $authorization): void
    {
        $this->models->deleteById($authorization->id);
    }

    public function findById(string $id): ?OAuth2Authorization
    {
        $row = $this->models->findById($id);

        return $row instanceof OAuth2AuthorizationModel ? self::map($row) : null;
    }

    public function findByToken(string $value, ?OAuth2TokenType $type = null): ?OAuth2Authorization
    {
        $row = $this->models->findFirstByTokenHash(TokenHash::of($value), $type);

        return $row === null ? null : self::map($row);
    }

    public function countActiveForClient(string $registeredClientId, DateTimeImmutable $now): int
    {
        $count = 0;
        foreach ($this->models->unexpiredForClient($registeredClientId, $now) as $row) {
            if (self::map($row)->isActive($now)) {
                $count++;
            }
        }

        return $count;
    }

    /** One `oauth2_authorizations` table, read by every worker: a count taken here describes the deployment. */
    public function processLocal(): bool
    {
        return false;
    }

    public function purgeExpired(DateTimeImmutable $now): int
    {
        return $this->models->purgeExpired($now);
    }

    public static function map(OAuth2AuthorizationModel $row): OAuth2Authorization
    {
        $tokens = [];
        foreach (OAuth2TokenType::cases() as $type) {
            $hash = self::text($row, "{$type->value}_hash");
            if ($hash === '') {
                continue;
            }
            $tokens[$type->value] = new OAuth2Token(
                $type,
                $hash,
                OAuth2ServerSchema::parseInstant($row->getAttribute("{$type->value}_issued_at")) ?? new DateTimeImmutable('@0'),
                OAuth2ServerSchema::parseInstant($row->getAttribute("{$type->value}_expires_at")),
                self::json(self::text($row, "{$type->value}_metadata")),
            );
        }

        $attributes = self::json(self::text($row, 'attributes'));
        $family = self::list(self::text($row, 'refresh_token_family'));
        if ($family !== []) {
            $attributes['refresh_token_family'] = $family;
        }

        return new OAuth2Authorization(
            self::text($row, 'id'),
            self::text($row, 'registered_client_id'),
            self::text($row, 'principal_name'),
            AuthorizationGrantType::from(self::text($row, 'authorization_grant_type')),
            self::list(self::text($row, 'authorized_scopes')),
            $attributes,
            $tokens,
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function row(OAuth2Authorization $authorization): array
    {
        $attributes = $authorization->attributes;
        unset($attributes['refresh_token_family']);

        $row = [
            'id' => $authorization->id,
            'registered_client_id' => $authorization->registeredClientId,
            'principal_name' => $authorization->principalName,
            'authorization_grant_type' => $authorization->authorizationGrantType->value,
            'authorized_scopes' => implode(' ', $authorization->authorizedScopes),
            'attributes' => json_encode($attributes, JSON_THROW_ON_ERROR),
            'refresh_token_family' => implode(' ', $authorization->refreshTokenFamily()),
            'expires_at' => OAuth2ServerSchema::instant($authorization->expiresAt()),
        ];
        foreach (OAuth2TokenType::cases() as $type) {
            $token = $authorization->token($type);
            $row["{$type->value}_hash"] = $token?->hash;
            $row["{$type->value}_issued_at"] = OAuth2ServerSchema::instant($token?->issuedAt);
            $row["{$type->value}_expires_at"] = OAuth2ServerSchema::instant($token?->expiresAt);
            $row["{$type->value}_metadata"] = $token === null ? null : json_encode($token->metadata, JSON_THROW_ON_ERROR);
        }

        return $row;
    }

    /** A text column as the string it holds: every column of this table is written as a string, and a NULL reads as ''. */
    private static function text(OAuth2AuthorizationModel $row, string $column): string
    {
        $value = $row->getAttribute($column);

        return is_string($value) ? $value : '';
    }

    /**
     * @return list<string>
     */
    private static function list(string $joined): array
    {
        return $joined === '' ? [] : array_values(array_filter(explode(' ', $joined), static fn (string $v): bool => $v !== ''));
    }

    /**
     * @return array<string,mixed>
     */
    private static function json(string $text): array
    {
        $decoded = $text === '' ? [] : json_decode($text, true, 512, JSON_THROW_ON_ERROR);

        /** @var array<string,mixed> */
        return is_array($decoded) ? $decoded : [];
    }
}
