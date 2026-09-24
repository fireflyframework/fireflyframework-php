<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures\Advice;

use Firefly\Kernel\Exception\Business\ConflictException;
use Firefly\Web\Attributes\ExceptionHandler;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;

#[RestController]
#[RequestMapping('/errors')]
final class ConflictController
{
    /** @return array<string,mixed> */
    #[GetMapping('/conflict')]
    public function conflict(): array
    {
        throw new CustomBusinessException('account is frozen');
    }

    /** @return array<string,mixed> */
    #[GetMapping('/another')]
    public function another(): array
    {
        throw new AnotherException('downstream refused');
    }

    /**
     * A LATIN-1 BYTE IN THE SENTENCE, thrown from a controller through the whole kernel — the shape a driver
     * message quoting a non-UTF-8 column arrives in. json_encode with JSON_THROW_ON_ERROR raised on this
     * byte from inside the problem renderer, i.e. from inside the handler already handling the failure, and
     * what reached the client was not a worse document but NO document: a blank 500 from the web server.
     * Only the real pipeline can falsify that, because the blank 500 is what the layer ABOVE the renderer
     * does with the exception the renderer threw.
     *
     * @return array<string,mixed>
     */
    #[GetMapping('/latin-1')]
    public function latin1(): array
    {
        throw new ConflictException("The ledger for caf\xE9 disagrees.", 'LEDGER_CONFLICT');
    }

    /**
     * The same route into the renderer, one step further: an extension member holding a value json_encode
     * refuses outright (INF), which substitution cannot answer and the minimal document must. Through the
     * pipeline rather than at the renderer's own front door, so the assertion is the one a caller can make.
     *
     * @return array<string,mixed>
     */
    #[GetMapping('/unencodable')]
    public function unencodable(): array
    {
        throw (new ConflictException('The ledger disagrees.', 'LEDGER_CONFLICT'))->withExtensions(['ratio' => INF]);
    }

    /**
     * Controller-LOCAL handler — beats any global #[ControllerAdvice] for CustomBusinessException. Its return
     * value renders at the exception's httpStatus() (409), not the route's 200 (exercises the matched-handler
     * status correction in Task 17's ControllerDispatcher).
     *
     * @return array<string,string>
     */
    #[ExceptionHandler(CustomBusinessException::class)]
    public function onConflict(CustomBusinessException $e): array
    {
        return ['handled' => 'local-conflict', 'code' => $e->errorCode()];
    }
}
