<?php

declare(strict_types=1);

namespace Firefly\OpenApi\Command;

use Firefly\OpenApi\Generator\OpenApiGenerator;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * `php artisan firefly:openapi` — writes the generated document to a file, or to stdout.
 *
 * The command exists so the document can be a BUILD ARTIFACT rather than only a live endpoint. Committing
 * the generated file is what lets a CI job diff it and fail a pull request that changed the public API
 * without saying so, and what lets a front-end repository regenerate its typed client from a checked-in
 * spec without booting the PHP application at all. It is also the only way to get a document out of a
 * deployment that keeps `firefly.openapi.enabled` off in production.
 *
 * STDOUT IS RAW, and that matters more than it looks. Console output goes through Symfony's formatter, which
 * treats `<...>` as markup — a `description` mentioning a generic type, or any angle bracket that reaches the
 * document from a docblock or config value, would either be swallowed or would throw on an unknown tag. The
 * whole point of stdout mode is `php artisan firefly:openapi > openapi.json` and piping straight into a client
 * generator, so the bytes must be exactly the bytes of the document; OUTPUT_RAW is what guarantees that, and
 * it is also why the confirmation line is printed ONLY in --output mode, where stdout is not the document.
 */
final class OpenApiCommand extends Command
{
    /** @var string */
    protected $signature = 'firefly:openapi
        {--output= : Write the document to this file instead of stdout (parent directories are created).}';

    /** @var string */
    protected $description = 'Generate the OpenAPI 3.1 document from the compiled route + constraint manifests.';

    public function handle(OpenApiGenerator $generator): int
    {
        $json = $generator->toJson();
        $target = $this->option('output');

        if (! is_string($target) || $target === '') {
            $this->output->writeln($json, OutputInterface::OUTPUT_RAW);

            return self::SUCCESS;
        }

        $directory = dirname($target);
        if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            $this->components->error("Could not create directory [{$directory}].");

            return self::FAILURE;
        }

        if (file_put_contents($target, $json.PHP_EOL) === false) {
            $this->components->error("Could not write [{$target}].");

            return self::FAILURE;
        }

        $document = $generator->generate();
        $paths = is_array($document['paths'] ?? null) ? $document['paths'] : [];

        $this->components->info(sprintf(
            'OpenAPI 3.1 document written to %s (%d path%s).',
            $target,
            count($paths),
            count($paths) === 1 ? '' : 's',
        ));

        return self::SUCCESS;
    }
}
