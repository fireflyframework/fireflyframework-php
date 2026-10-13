<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core;

/** Which of the three engines owns an execution; the value is what persistence and events carry. */
enum ExecutionPattern: string
{
    case Saga = 'SAGA';
    case Tcc = 'TCC';
    case Workflow = 'WORKFLOW';
}
