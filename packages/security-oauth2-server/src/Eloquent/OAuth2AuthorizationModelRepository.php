<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Eloquent;

use DateTimeImmutable;
use Firefly\Data\Repository\EloquentRepository;
use Firefly\Security\OAuth2\Server\Authorization\OAuth2TokenType;
use Illuminate\Database\Eloquent\Builder;

/**
 * The repository over oauth2_authorizations, with the three queries the port needs that no derived name says:
 * a lookup by hash in one indexed column (a typed token) or in every one of them (any token), falling back to
 * the refresh-token family only on a miss; the unexpired rows of a client; and the purge. Each runs inside
 * translating(), so a driver failure is a DataAccessException.
 *
 * @extends EloquentRepository<OAuth2AuthorizationModel>
 */
final class OAuth2AuthorizationModelRepository extends EloquentRepository
{
    protected string $model = OAuth2AuthorizationModel::class;

    /**
     * The row holding the hash in the given type's column — or, with no type, in any of the four columns — and,
     * when that misses and the type is refresh_token or unknown, the row whose family holds it, which is how a
     * replayed refresh token is recognised. Two statements on purpose: the first is an equality on an indexed
     * `<type>_hash` column (or an OR of the four, each indexed), which is what every token grant, introspection
     * and revocation runs; the family is a space-joined text column that only a leading-wildcard LIKE can search,
     * and an OR with that arm in the same WHERE would make MySQL and Postgres abandon the hash indexes and scan the
     * table on every request. Kept as a second statement, the scan is paid only on the miss — the rare replay
     * path — and the common path stays an index lookup. A hex hash carries no LIKE metacharacter, so the pattern
     * is the literal hash.
     */
    public function findFirstByTokenHash(string $hash, ?OAuth2TokenType $type): ?OAuth2AuthorizationModel
    {
        return $this->translating(function () use ($hash, $type): ?OAuth2AuthorizationModel {
            $current = OAuth2AuthorizationModel::query();

            if ($type !== null) {
                $current->where("{$type->value}_hash", $hash);
            } else {
                $current->where(function (Builder $any) use ($hash): void {
                    foreach (OAuth2ServerSchema::TOKEN_PREFIXES as $prefix) {
                        $any->orWhere("{$prefix}_hash", $hash);
                    }
                });
            }

            $row = $current->first();
            if ($row !== null || ($type !== null && $type !== OAuth2TokenType::RefreshToken)) {
                return $row;
            }

            return OAuth2AuthorizationModel::query()->where('refresh_token_family', 'like', "%{$hash}%")->first();
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
