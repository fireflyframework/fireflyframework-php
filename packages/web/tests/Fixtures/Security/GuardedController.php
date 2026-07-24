<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures\Security;

final class GuardedController
{
    public bool $ran = false;

    public function index(): string
    {
        $this->ran = true;

        return 'ok';
    }

    public function denied(): string
    {
        $this->ran = true;

        return 'should-not-run';
    }
}
