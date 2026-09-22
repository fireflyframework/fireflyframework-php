<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures\Security;

use Firefly\Kernel\Exception\Security\AuthorizationException;
use Firefly\Web\Security\ControllerSecurityGuard;

final class RecordingSecurityGuard implements ControllerSecurityGuard
{
    /** @var list<array{class: string, method: string, args: array<int,mixed>}> */
    public array $calls = [];

    /** @var list<array{class: string, method: string, args: array<int,mixed>}> */
    public array $afterCalls = [];

    public function __construct(
        private readonly ?string $denyMethod = null,
        private readonly mixed $replaceResultWith = null,
    ) {}

    public function check(string $controllerClass, string $method, array $args): void
    {
        $this->calls[] = ['class' => $controllerClass, 'method' => $method, 'args' => $args];
        if ($method === $this->denyMethod) {
            throw new AuthorizationException('Denied by test guard.');
        }
    }

    public function afterInvocation(string $controllerClass, string $method, array $args, mixed $result): mixed
    {
        $this->afterCalls[] = ['class' => $controllerClass, 'method' => $method, 'args' => $args];

        return $this->replaceResultWith ?? $result;
    }
}
