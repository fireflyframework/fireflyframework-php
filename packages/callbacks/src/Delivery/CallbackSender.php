<?php

declare(strict_types=1);

namespace Firefly\Callbacks\Delivery;

/** POSTs one delivery and answers the HTTP status; a transport failure (refused, timed out) throws. */
interface CallbackSender
{
    /**
     * @param  array<string, string>  $headers
     */
    public function send(string $url, string $body, array $headers, float $timeoutSeconds): int;
}
