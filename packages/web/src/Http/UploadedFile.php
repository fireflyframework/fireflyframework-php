<?php

declare(strict_types=1);

namespace Firefly\Web\Http;

use Firefly\Kernel\Exception\Framework\ConfigurationException;
use Firefly\Kernel\Exception\Infrastructure\InfrastructureException;
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
        $realPath = $file->getRealPath();
        if ($realPath === false) {
            throw new InfrastructureException('Uploaded file has no readable temporary path.');
        }

        return new self(
            filename: $file->getClientOriginalName(),
            mimeType: (string) $file->getClientMimeType(),
            size: (int) $file->getSize(),
            temporaryPath: $realPath,
        );
    }

    public function contents(): string
    {
        // Guard with is_file() so a missing path throws without a native warning; @ covers the
        // rarer exists-but-unreadable case (permissions), which is surfaced as an exception below.
        $data = is_file($this->temporaryPath) ? @file_get_contents($this->temporaryPath) : false;

        if ($data === false) {
            throw new InfrastructureException("Could not read uploaded file at {$this->temporaryPath}.");
        }

        return $data;
    }

    public function store(string $directory, ?string $name = null): string
    {
        $filename = $name ?? $this->filename;
        if ($filename === '' || $filename === '.' || $filename === '..'
            || str_contains($filename, '/') || str_contains($filename, '\\') || str_contains($filename, "\0")) {
            throw new ConfigurationException("Refusing to store an uploaded file under an unsafe name: {$filename}.");
        }

        if (! is_dir($directory) && ! @mkdir($directory, 0o775, true) && ! is_dir($directory)) {
            throw new InfrastructureException("Could not create upload directory {$directory}.");
        }

        $target = rtrim($directory, '/').'/'.$filename;
        if (@file_put_contents($target, $this->contents()) === false) {
            throw new InfrastructureException("Could not store uploaded file to {$target}.");
        }

        return $target;
    }
}
