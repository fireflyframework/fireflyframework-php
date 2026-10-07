<?php

declare(strict_types=1);

use Firefly\FeatureFlags\Store\FeatureFlagSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * The shared feature-flag store tables, on the connection firefly.feature-flags.sources.store.connection names
 * (null = the default). Idempotent: when PyFly already created them in a shared database, this does nothing.
 */
return new class extends Migration
{
    public function getConnection(): ?string
    {
        $connection = config('firefly.feature-flags.sources.store.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public function up(): void
    {
        FeatureFlagSchema::create(Schema::connection($this->getConnection()));
    }

    public function down(): void
    {
        FeatureFlagSchema::drop(Schema::connection($this->getConnection()));
    }
};
