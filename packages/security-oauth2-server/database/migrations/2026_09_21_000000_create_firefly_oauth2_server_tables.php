<?php

declare(strict_types=1);

use Firefly\Security\OAuth2\Server\Eloquent\OAuth2ServerSchema;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        OAuth2ServerSchema::create();
    }

    public function down(): void
    {
        OAuth2ServerSchema::drop();
    }
};
