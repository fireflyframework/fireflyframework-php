<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Tests\BindingFixture;

final class Collaborator
{
    public function value(): string
    {
        return 'injected';
    }
}
