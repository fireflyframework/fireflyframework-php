<?php

declare(strict_types=1);

namespace Firefly\FeatureFlags\Tests\Support;

use Firefly\FeatureFlags\FeatureFlags;
use OpenFeature\interfaces\flags\EvaluationContext;
use OpenFeature\interfaces\hooks\Hook;
use OpenFeature\interfaces\hooks\HookContext;
use OpenFeature\interfaces\hooks\HookHints;
use OpenFeature\interfaces\provider\ResolutionDetails;
use Throwable;

/** An OpenFeature hook that records each evaluation it saw finish, with the preview hint the caller passed. */
final class RecordingHook implements Hook
{
    /** @var list<array{flag: string, reason: ?string, preview: mixed}> */
    public array $evaluations = [];

    public function before(HookContext $context, HookHints $hints): ?EvaluationContext
    {
        return null;
    }

    public function after(HookContext $context, ResolutionDetails $details, HookHints $hints): void
    {
        $this->evaluations[] = [
            'flag' => $context->getFlagKey(),
            'reason' => $details->getReason(),
            'preview' => $hints->get(FeatureFlags::PREVIEW_HINT),
        ];
    }

    public function error(HookContext $context, Throwable $error, HookHints $hints): void {}

    public function finally(HookContext $context, HookHints $hints): void {}

    public function supportsFlagValueType(string $flagValueType): bool
    {
        return true;
    }
}
