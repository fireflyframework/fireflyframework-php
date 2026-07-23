<?php

declare(strict_types=1);

namespace Firefly\Eda;

/**
 * The serialization SEAM a broker adapter uses to put an EventEnvelope on the wire as bytes and read it back.
 * JSON is the only shipped implementation (M9); Avro/Protobuf are documented seams selected by
 * firefly.eda.serialization_format and rejected with a SerializationException until their own opt-in packages
 * exist. The in-memory and queue adapters do NOT use this on their default path (in-process / PHP job
 * serialization respectively) — it exists for the SP-4 broker adapters.
 */
interface Serializer
{
    public function serialize(EventEnvelope $envelope): string;

    public function deserialize(string $raw): EventEnvelope;
}
