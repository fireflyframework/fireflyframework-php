<?php

declare(strict_types=1);

namespace Firefly\Eda\Exception;

use Firefly\Kernel\Exception\Infrastructure\InfrastructureException;
use Throwable;

/**
 * Thrown when an eda Serializer cannot serialise/deserialise an EventEnvelope (malformed bytes, wrong shape, or an
 * unsupported serialization-format selected in config). Extends the frozen kernel InfrastructureException (httpStatus
 * 500), mirroring how firefly/data defined its own tx-demarcation exceptions over the same base. Fail-loud, never silent.
 */
final class SerializationException extends InfrastructureException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct($message, 'EDA_SERIALIZATION_ERROR', 500, previous: $previous);
    }
}
