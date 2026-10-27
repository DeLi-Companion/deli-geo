<?php

declare(strict_types=1);

namespace DeLiCompanion\Geo\Manifest;

use DateTimeImmutable;

final readonly class Manifest
{
    /**
     * @param  array<string, string>  $files  role => path relative to the build directory
     * @param  array<string, string>  $checksums  relative path => SHA-256
     */
    public function __construct(
        public string $directory,
        public int $formatVersion,
        public string $buildId,
        public DateTimeImmutable $createdAt,
        public array $files,
        public array $checksums,
    ) {}

    public function path(string $role): string
    {
        return $this->directory.'/'.$this->relativePath($role);
    }

    public function verifiedPath(string $role): string
    {
        $relativePath = $this->relativePath($role);
        $path = $this->directory.'/'.$relativePath;

        if (is_dir($path)) {
            throw InvalidManifest::because($this->directory, "{$relativePath} is a directory, not a file");
        }

        $expected = $this->checksums[$relativePath]
            ?? throw InvalidManifest::because($this->directory, "the checksums file has no entry for {$relativePath}");

        if (! is_file($path)) {
            throw InvalidManifest::because($this->directory, "{$relativePath} is missing");
        }

        $actual = (string) hash_file('sha256', $path);

        if (! hash_equals($expected, $actual)) {
            throw new ChecksumMismatch($path, $expected, $actual);
        }

        return $path;
    }

    private function relativePath(string $role): string
    {
        return $this->files[$role] ?? throw InvalidManifest::because($this->directory, "files has no entry for {$role}");
    }
}
