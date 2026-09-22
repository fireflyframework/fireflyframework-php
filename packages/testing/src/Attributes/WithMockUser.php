<?php

declare(strict_types=1);

namespace Firefly\Testing\Attributes;

use Attribute;

/**
 * Spring's @WithMockUser: run the test (or every test of the class) as a signed-in principal. `roles` are
 * prefixed with `ROLE_` unless already so; `authorities` are taken verbatim. A method-level attribute wins
 * over a class-level one. Honoured by FireflyTestCase::setUp() through actingAsPrincipal(), so it covers
 * direct calls, HTTP requests through the filters, the dispatcher guard and proxied beans alike.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final readonly class WithMockUser
{
    /**
     * @param  list<string>  $roles
     * @param  list<string>  $authorities
     */
    public function __construct(
        public string $name = 'user',
        public array $roles = ['USER'],
        public array $authorities = [],
    ) {}

    /**
     * The authority strings the principal carries: every role with its `ROLE_` prefix (added unless the
     * role already spells it), followed by the verbatim authorities.
     *
     * @return list<string>
     */
    public function resolvedAuthorities(): array
    {
        $resolved = [];
        foreach ($this->roles as $role) {
            $resolved[] = str_starts_with($role, 'ROLE_') ? $role : 'ROLE_'.$role;
        }

        return [...$resolved, ...$this->authorities];
    }
}
