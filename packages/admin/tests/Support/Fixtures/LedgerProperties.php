<?php

declare(strict_types=1);

namespace Firefly\Admin\Tests\Support\Fixtures;

use Firefly\Config\Attributes\ConfigProperties;

/**
 * A #[ConfigProperties] DTO that did NOT bind, because its #[Profile] requirement is not satisfied.
 *
 * Declared in the manifest and left UNBOUND in the container on purpose: that is exactly the state
 * ConfigRegistrar leaves a profile-gated DTO in, and ConfigPropsEndpoint reports it as `bound: false` with
 * the profiles it wanted. Without one of these the Not-bound panel never renders and the page's second
 * listing is asserted by nothing.
 */
#[ConfigProperties(prefix: 'ledger')]
final readonly class LedgerProperties
{
    public function __construct(public string $postingMode = 'double-entry') {}
}
