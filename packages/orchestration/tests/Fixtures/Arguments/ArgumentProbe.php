<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Tests\Fixtures\Arguments;

use Firefly\Orchestration\Core\ExecutionContext;
use Throwable;

/** Records what every binding kind delivered; bound as a singleton so the test reads what the runner passed. */
final class ArgumentProbe
{
    /** @var array<string, mixed> */
    public array $seen = [];

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $variables
     */
    public function everything(
        mixed $implicit,
        ExecutionContext $context,
        mixed $whole,
        mixed $amount,
        mixed $reserved,
        mixed $undo,
        ?Throwable $undoError,
        mixed $hold,
        ?string $tenant,
        array $headers,
        mixed $tier,
        array $variables,
        string $correlationId,
        string $optional = 'default',
    ): string {
        $this->seen = [
            'implicit' => $implicit, 'context' => $context->correlationId, 'whole' => $whole, 'amount' => $amount,
            'reserved' => $reserved, 'undo' => $undo, 'undoError' => $undoError?->getMessage(), 'hold' => $hold,
            'tenant' => $tenant, 'headers' => $headers, 'tier' => $tier, 'variables' => $variables,
            'correlationId' => $correlationId, 'optional' => $optional,
        ];

        return 'probed';
    }

    public function required(mixed $value): string
    {
        return 'never';
    }
}
