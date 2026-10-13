<?php

declare(strict_types=1);

namespace Firefly\Orchestration\Core\Exception;

/** A #[WaitForSignal(timeoutMs: …)] elapsed before the signal arrived. */
final class SignalTimeoutException extends StepTimeoutException {}
