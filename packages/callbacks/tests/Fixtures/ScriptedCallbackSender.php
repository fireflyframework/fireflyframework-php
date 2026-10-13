<?php

declare(strict_types=1);

namespace Firefly\Callbacks\Tests\Fixtures;

use Firefly\Callbacks\Delivery\CallbackSender;
use Throwable;

/** Answers each send() with the next scripted status (or throws the next scripted failure); 204 when the script ends. */
final class ScriptedCallbackSender implements CallbackSender
{
    /** @var list<array{url: string, body: string, headers: array<string, string>}> */
    public array $sent = [];

    /**
     * @param  list<int|Throwable>  $answers
     */
    public function __construct(private array $answers = []) {}

    public function send(string $url, string $body, array $headers, float $timeoutSeconds): int
    {
        $this->sent[] = ['url' => $url, 'body' => $body, 'headers' => $headers];
        $answer = array_shift($this->answers) ?? 204;
        if ($answer instanceof Throwable) {
            throw $answer;
        }

        return $answer;
    }
}
