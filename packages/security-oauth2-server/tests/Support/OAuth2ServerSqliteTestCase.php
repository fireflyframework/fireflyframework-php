<?php

declare(strict_types=1);

namespace Firefly\Security\OAuth2\Server\Tests\Support;

use Firefly\Security\OAuth2\Server\Eloquent\OAuth2ServerSchema;
use Firefly\Testing\FireflyDatabaseTestCase;

/** sqlite :memory: with the three server tables created from the shipped schema — what the migration runs. Not `final`. */
class OAuth2ServerSqliteTestCase extends FireflyDatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        OAuth2ServerSchema::create();
    }
}
