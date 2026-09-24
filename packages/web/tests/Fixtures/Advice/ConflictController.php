<?php

declare(strict_types=1);

namespace Firefly\Web\Tests\Fixtures\Advice;

use Firefly\Kernel\Exception\Business\ConflictException;
use Firefly\Web\Attributes\ExceptionHandler;
use Firefly\Web\Attributes\GetMapping;
use Firefly\Web\Attributes\RequestMapping;
use Firefly\Web\Attributes\RestController;
use JsonSerializable;
use RuntimeException;

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
     * THE ARM JSON_THROW_ON_ERROR NEVER REACHES. The two routes above are values json_encode REFUSES and
     * reports; this one is a value whose own code json_encode CALLS — and a jsonSerialize() or an Eloquent
     * accessor that throws propagates straight out of json_encode with no error state set and no
     * JsonException in it, which is why catching JsonException left the renderer able to throw after all.
     * The member is `mixed` and chosen at the throw site, and the realistic version of this object is a
     * model whose accessor reads the database — the thing that already failed, which is why the renderer
     * was running at all. Through the pipeline, because a blank 500 is again what the layer above does
     * with the exception the renderer threw, and a direct call can only observe the throw.
     *
     * @return array<string,mixed>
     */
    #[GetMapping('/throwing-extension')]
    public function throwingExtension(): array
    {
        $balance = new class implements JsonSerializable
        {
            public function jsonSerialize(): mixed
            {
                throw new RuntimeException('the accessor could not read the balance either');
            }
        };

        throw (new ConflictException('The ledger disagrees.', 'LEDGER_CONFLICT'))->withExtensions(['balance' => $balance]);
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
