<?php

declare(strict_types=1);

namespace Firefly\Testing\Tests\Security;

use Firefly\Security\Core\Authentication;
use Firefly\Security\Core\SecurityContextHolder;
use Firefly\Testing\Attributes\WithMockUser;
use Firefly\Testing\FireflyTestCase;

/** PHPUnit-style, because the attribute lives on a class and on a method — the shapes Spring's @WithMockUser has. */
#[WithMockUser(name: 'class-user', roles: ['USER'])]
final class WithMockUserTest extends FireflyTestCase
{
    protected function tearDown(): void
    {
        SecurityContextHolder::clearContext();

        parent::tearDown();
    }

    public function test_the_class_attribute_signs_the_default_user_in_with_role_prefixes(): void
    {
        $authentication = SecurityContextHolder::getAuthentication();

        $this->assertInstanceOf(Authentication::class, $authentication);
        $this->assertSame('class-user', $authentication->getName());
        $this->assertSame(['ROLE_USER'], $authentication->authorityStrings());
    }

    #[WithMockUser(name: 'admin', roles: ['ADMIN', 'ROLE_AUDIT'], authorities: ['orders:write'])]
    public function test_the_method_attribute_wins_over_the_class_one(): void
    {
        $authentication = SecurityContextHolder::getAuthentication();

        $this->assertInstanceOf(Authentication::class, $authentication);
        $this->assertSame('admin', $authentication->getName());
        $this->assertSame(['ROLE_ADMIN', 'ROLE_AUDIT', 'orders:write'], $authentication->authorityStrings());
    }

    public function test_the_defaults_read_like_spring(): void
    {
        $this->assertSame(['ROLE_USER'], (new WithMockUser)->resolvedAuthorities());
        $this->assertSame('user', (new WithMockUser)->name);
    }
}
