<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Authorization;

use DateTimeImmutable;

/**
 * The `memory` driver: a per-process map. Right for tests and a single dev server; a deployment with more than
 * one worker wants `eloquent`, because a code issued by one process must be redeemable by another. Every hash
 * comparison goes through hash_equals(): the hashes are not secrets, but a constant-time compare costs nothing
 * here and keeps the memory and Eloquent drivers answering the same question the same way.
 */
final class InMemoryOAuth2AuthorizationService implements OAuth2AuthorizationService
{
    /** @var array<string,OAuth2Authorization> */
    private array $authorizations = [];

    public function save(OAuth2Authorization $authorization): void
    {
        $this->authorizations[$authorization->id] = $authorization->withoutTokenValues();
    }

    public function remove(OAuth2Authorization $authorization): void
    {
        unset($this->authorizations[$authorization->id]);
    }

    public function findById(string $id): ?OAuth2Authorization
    {
        return $this->authorizations[$id] ?? null;
    }

    public function findByToken(string $value, ?OAuth2TokenType $type = null): ?OAuth2Authorization
    {
        $hash = TokenHash::of($value);

        foreach ($this->authorizations as $authorization) {
            if ($type !== null) {
                $token = $authorization->token($type);
                if ($token !== null && hash_equals($token->hash, $hash)) {
                    return $authorization;
                }
                if ($type === OAuth2TokenType::RefreshToken && in_array($hash, $authorization->refreshTokenFamily(), true)) {
                    return $authorization;
                }

                continue;
            }

            foreach ($authorization->tokens as $token) {
                if (hash_equals($token->hash, $hash)) {
                    return $authorization;
                }
            }
            if (in_array($hash, $authorization->refreshTokenFamily(), true)) {
                return $authorization;
            }
        }

        return null;
    }

    public function countActiveForClient(string $registeredClientId, DateTimeImmutable $now): int
    {
        $count = 0;
        foreach ($this->authorizations as $authorization) {
            if ($authorization->registeredClientId === $registeredClientId && $authorization->isActive($now)) {
                $count++;
            }
        }

        return $count;
    }

    public function purgeExpired(DateTimeImmutable $now): int
    {
        $removed = 0;
        foreach ($this->authorizations as $id => $authorization) {
            $expiresAt = $authorization->expiresAt();
            if ($expiresAt !== null && $expiresAt <= $now) {
                unset($this->authorizations[$id]);
                $removed++;
            }
        }

        return $removed;
    }
}
