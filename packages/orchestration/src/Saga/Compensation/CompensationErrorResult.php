<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Saga\Compensation;

/**
 * What to do after a compensation failed (jafly CompensationErrorResult): carry on with the next step, try
 * this compensation again, stop compensating and fail the saga, skip this step, or record it as compensated
 * anyway (the operator knows the effect is harmless).
 */
enum CompensationErrorResult
{
    case Continue;
    case Retry;
    case FailSaga;
    case SkipStep;
    case MarkCompensated;
}
