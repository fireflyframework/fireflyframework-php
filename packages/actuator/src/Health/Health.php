<?php

declare(strict_types=1);

namespace Firefly\Actuator\Health;

/** A health reading: a Status plus free-form details (masked/filtered by show-details at the endpoint). */
final readonly class Health
{
    /**
     * @param  array<string, mixed>  $details
     */
    public function __construct(
        public Status $status,
        public array $details = [],
    ) {}

    /** @param array<string, mixed> $details */
    public static function up(array $details = []): self
    {
        return new self(Status::Up, $details);
    }

    /** @param array<string, mixed> $details */
    public static function down(array $details = []): self
    {
        return new self(Status::Down, $details);
    }

    /** @param array<string, mixed> $details */
    public static function outOfService(array $details = []): self
    {
        return new self(Status::OutOfService, $details);
    }

    /** @param array<string, mixed> $details */
    public static function unknown(array $details = []): self
    {
        return new self(Status::Unknown, $details);
    }
}
