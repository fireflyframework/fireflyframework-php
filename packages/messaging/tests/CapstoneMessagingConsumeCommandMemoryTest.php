<?php

declare(strict_types=1);

use Firefly\Messaging\Tests\Support\MessagingArtisan;
use Firefly\Messaging\Tests\Support\MessagingCapstoneTestCase;

uses(MessagingCapstoneTestCase::class);

it('refuses to consume on a broker that has no consumer loop', function () {
    /** @var MessagingCapstoneTestCase $this */
    MessagingArtisan::outputContains($this->artisan('firefly:messaging:consume', ['--max-messages' => 1]), 1, 'has no consumer loop');
});
