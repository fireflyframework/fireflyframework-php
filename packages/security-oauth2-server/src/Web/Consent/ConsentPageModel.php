<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Web\Consent;

/**
 * Everything the consent page needs, and what a `consent.view` receives as `$consent`: the client, the person,
 * each requested scope with a sentence and whether it was allowed before, the pending state, the root-relative
 * action and the session token. The framework page and a Blade override get exactly the same facts.
 */
final readonly class ConsentPageModel
{
    /**
     * @param  list<array{scope: string, description: string, approved: bool}>  $scopes
     */
    public function __construct(
        public string $title,
        public string $clientName,
        public string $clientId,
        public string $principalName,
        public array $scopes,
        public string $state,
        public string $action,
        public string $csrfToken,
    ) {}
}
