<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Eloquent;

use DateTimeImmutable;
use Firefly\Data\Repository\EloquentRepository;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2TokenType;
use Illuminate\Database\Eloquent\Builder;

/**
 * The repository over oauth2_authorizations, with the three queries the port needs that no derived name says:
 * a lookup by hash in one column (a typed token) or in every column and the family (any token), the unexpired
 * rows of a client, and the purge. Each runs inside translating(), so a driver failure is a DataAccessException.
 *
 * @extends EloquentRepository<OAuth2AuthorizationModel>
 */
final class OAuth2AuthorizationModelRepository extends EloquentRepository
{
    protected string $model = OAuth2AuthorizationModel::class;

    /**
     * The row holding the hash in the given type's column — or, for a refresh token, in the family as well, which
     * is how a replayed refresh token is recognised — or, with no type, in any of the four columns or the family.
     * A hex hash carries no LIKE metacharacter, so the pattern is the literal hash.
     */
    public function findFirstByTokenHash(string $hash, ?OAuth2TokenType $type): ?OAuth2AuthorizationModel
    {
        return $this->translating(function () use ($hash, $type): ?OAuth2AuthorizationModel {
            $query = OAuth2AuthorizationModel::query();

            if ($type !== null) {
                $query->where("{$type->value}_hash", $hash);
                if ($type === OAuth2TokenType::RefreshToken) {
                    $query->orWhere('refresh_token_family', 'like', "%{$hash}%");
                }
            } else {
                $query->where(function (Builder $any) use ($hash): void {
                    foreach (OAuth2ServerSchema::TOKEN_PREFIXES as $prefix) {
                        $any->orWhere("{$prefix}_hash", $hash);
                    }
                    $any->orWhere('refresh_token_family', 'like', "%{$hash}%");
                });
            }

            return $query->first();
        });
    }

    /**
     * @return list<OAuth2AuthorizationModel>
     */
    public function unexpiredForClient(string $registeredClientId, DateTimeImmutable $now): array
    {
        return $this->translating(fn (): array => array_values(OAuth2AuthorizationModel::query()
            ->where('registered_client_id', $registeredClientId)
            ->where('expires_at', '>', (string) OAuth2ServerSchema::instant($now))
            ->get()
            ->all()));
    }

    /** The rows whose latest token expired at or before $now, deleted in one statement; the number removed. */
    public function purgeExpired(DateTimeImmutable $now): int
    {
        return $this->translating(function () use ($now): int {
            $deleted = OAuth2AuthorizationModel::query()->where('expires_at', '<=', (string) OAuth2ServerSchema::instant($now))->delete();

            return is_int($deleted) ? $deleted : 0;
        });
    }
}
