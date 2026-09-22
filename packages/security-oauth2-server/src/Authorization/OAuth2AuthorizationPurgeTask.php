<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Authorization;

use DateTimeImmutable;
use Firefly\Container\Attributes\Component;
use Firefly\Context\Condition\Attributes\ConditionalOnProperty;
use Psr\Log\LoggerInterface;

/**
 * The scheduled purge of expired authorizations. NOT annotated with #[Scheduled]: the scheduling scanner reads
 * `firefly.scan.paths` — the application's code — and never a framework package, so the attribute would be
 * decoration. OAuth2AuthorizationPurgeSchedulePass contributes the descriptor to the ScheduledManifest instead,
 * under `authorizations.purge.enabled` and `authorizations.purge.cron`, and ScheduleWiringPass runs purge() under
 * the DistributedLock named LOCK exactly as it runs any #[Scheduled] method. A bean rather than a closure so the
 * task has a name on `firefly:schedule`, `/actuator/scheduledtasks` and the admin Scheduled page.
 *
 * The log line carries the count only — never an id, a principal or a client — because a purge is bookkeeping,
 * and the one thing an operator wants from it is proof that the table is not growing without bound.
 */
#[Component]
#[ConditionalOnProperty(name: 'firefly.security.enabled', havingValue: 'true')]
#[ConditionalOnProperty(name: 'firefly.security.oauth2.server.enabled', havingValue: 'true')]
final class OAuth2AuthorizationPurgeTask
{
    public const string LOCK = 'firefly.oauth2.purge';

    public function __construct(
        private readonly OAuth2AuthorizationService $authorizations,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function purge(): int
    {
        $removed = $this->authorizations->purgeExpired(new DateTimeImmutable);
        $this->logger?->info("OAuth2 authorization purge removed {$removed} expired authorization(s).", ['removed' => $removed]);

        return $removed;
    }
}
