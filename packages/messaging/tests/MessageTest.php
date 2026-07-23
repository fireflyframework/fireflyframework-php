<?php

declare(strict_types=1);

use Firefly\Messaging\Message;

it('carries topic/value/key/headers and withHeaders merges (new wins) without mutating', function () {
    $message = new Message('payments', "\x00\x01raw", 'acct-9', ['x-trace' => 't']);

    expect($message->topic)->toBe('payments')
        ->and($message->value)->toBe("\x00\x01raw")
        ->and($message->key)->toBe('acct-9')
        ->and($message->headers)->toBe(['x-trace' => 't']);

    $merged = $message->withHeaders(['x-trace' => 'u', 'x-extra' => 'e']);
    expect($merged->headers)->toBe(['x-trace' => 'u', 'x-extra' => 'e'])
        ->and($message->headers)->toBe(['x-trace' => 't']); // original untouched
});
