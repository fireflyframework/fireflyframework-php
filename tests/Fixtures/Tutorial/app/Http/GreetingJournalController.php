<?php

declare(strict_types=1);

namespace App\Http;

use App\Greetings\GreetingCounts;
use App\Greetings\GreetingJournal;
use App\Greetings\GreetingJournals;
use Firefly\Kernel\Exception\Business\ResourceNotFoundException;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\PathVariable;
use Firefly\Web\Attributes\PostMapping;
use Firefly\Web\Attributes\RestController;

#[RestController]
final class GreetingJournalController
{
    public function __construct(private readonly GreetingJournals $journals) {}

    /** @return array{name: string, times: int} */
    #[PostMapping('/journal/{name}', status: 201)]
    public function say(#[PathVariable] string $name): array
    {
        $message = "Hello, {$name}!";
        $journal = $this->journals->find($name);

        if ($journal === null) {
            $journal = GreetingJournal::begin($name, $message);
        } else {
            $journal->say($message);
        }

        $this->journals->save($journal);

        return ['name' => $name, 'times' => $journal->times()];
    }

    /** @return array{name: string, times: int, lastMessage: string} */
    #[GetMapping('/journal/{name}')]
    public function show(#[PathVariable] string $name): array
    {
        return GreetingCounts::find($name)
            ?? throw new ResourceNotFoundException("No greeting to {$name} has been projected yet.");
    }
}
