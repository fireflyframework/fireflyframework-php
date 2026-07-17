<?php

declare(strict_types=1);

namespace Firefly\Web\Http;

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Illuminate\Http\UploadedFile as IlluminateUploadedFile;

/**
 * A framework-neutral uploaded-file value object handed to controller handlers, built from Laravel's own
 * uploaded file by the ArgumentResolver. Holds the metadata plus the temporary path; contents()/store()
 * read from it lazily.
 */
final readonly class UploadedFile
{
    public function __construct(
        public string $filename,
        public string $mimeType,
        public int $size,
        private string $temporaryPath,
    ) {}

    public static function fromIlluminate(IlluminateUploadedFile $file): self
    {
        return new self(
            filename: $file->getClientOriginalName(),
            mimeType: (string) $file->getClientMimeType(),
            size: (int) $file->getSize(),
            temporaryPath: (string) $file->getRealPath(),
        );
    }

    public function contents(): string
    {
        $data = file_get_contents($this->temporaryPath);

        return $data === false ? '' : $data;
    }

    public function store(string $directory, ?string $name = null): string
    {
        if (! is_dir($directory) && ! mkdir($directory, 0o775, true) && ! is_dir($directory)) {
            throw new ConfigurationException("Could not create upload directory {$directory}.");
        }

        $target = rtrim($directory, '/').'/'.($name ?? $this->filename);
        if (file_put_contents($target, $this->contents()) === false) {
            throw new ConfigurationException("Could not store uploaded file to {$target}.");
        }

        return $target;
    }
}
