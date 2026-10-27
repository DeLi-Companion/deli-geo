<?php

declare(strict_types=1);

namespace DeLiCompanion\Geo\Manifest;

use DateTimeImmutable;
use Exception;
use JsonException;

final class ManifestReader
{
    private const string BUILD_ID_PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    private const string CREATED_AT_PATTERN = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/';

    public function read(string $buildDirectory): Manifest
    {
        $directory = rtrim($buildDirectory, '/');
        $data = $this->decode($directory);

        $formatVersion = $data['format_version'] ?? null;
        if (! is_int($formatVersion)) {
            throw InvalidManifest::because($directory, 'format_version must be an integer');
        }
        if (! FormatVersion::isSupported($formatVersion)) {
            throw new UnsupportedFormatVersion($directory, $formatVersion);
        }

        $buildId = $data['build_id'] ?? null;
        if (! is_string($buildId) || preg_match(self::BUILD_ID_PATTERN, $buildId) !== 1) {
            throw InvalidManifest::because($directory, 'build_id must be a date like 2026-10-27');
        }
        if ($buildId !== basename($directory)) {
            throw InvalidManifest::because($directory, 'build_id does not match the directory name');
        }

        $checksumsFile = $data['checksums_file'] ?? 'SHA256SUMS';
        if (! is_string($checksumsFile) || ! $this->isRelativePath($checksumsFile)) {
            throw InvalidManifest::because($directory, 'checksums_file must be a relative path');
        }

        return new Manifest(
            $directory,
            $formatVersion,
            $buildId,
            $this->createdAt($directory, $data['created_at'] ?? null),
            $this->files($directory, $data['files'] ?? null),
            $this->checksums($directory, $checksumsFile),
        );
    }

    /**
     * @return array<mixed>
     */
    private function decode(string $directory): array
    {
        $path = $directory.'/manifest.json';

        if (! is_file($path)) {
            throw InvalidManifest::because($directory, 'manifest.json is missing');
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw InvalidManifest::because($directory, 'manifest.json is not valid JSON: '.$exception->getMessage());
        }

        if (! is_array($data)) {
            throw InvalidManifest::because($directory, 'manifest.json is not an object');
        }

        return $data;
    }

    private function createdAt(string $directory, mixed $createdAt): DateTimeImmutable
    {
        if (! is_string($createdAt) || preg_match(self::CREATED_AT_PATTERN, $createdAt) !== 1) {
            throw InvalidManifest::because($directory, 'created_at must be a UTC timestamp like 2026-10-27T10:00:00Z');
        }

        try {
            return new DateTimeImmutable($createdAt);
        } catch (Exception) {
            throw InvalidManifest::because($directory, 'created_at must be a UTC timestamp like 2026-10-27T10:00:00Z');
        }
    }

    /**
     * @return array<string, string>
     */
    private function files(string $directory, mixed $files): array
    {
        if (! is_array($files) || $files === []) {
            throw InvalidManifest::because($directory, 'files must map roles to relative paths');
        }

        $validated = [];
        foreach ($files as $role => $path) {
            if (! is_string($role) || ! is_string($path) || ! $this->isRelativePath($path)) {
                throw InvalidManifest::because($directory, 'files must map roles to relative paths');
            }
            $validated[$role] = $path;
        }

        return $validated;
    }

    /**
     * @return array<string, string>
     */
    private function checksums(string $directory, string $checksumsFile): array
    {
        $path = $directory.'/'.$checksumsFile;

        if (! is_file($path)) {
            throw InvalidManifest::because($directory, "{$checksumsFile} is missing");
        }

        $checksums = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            if (preg_match('/^([0-9a-f]{64}) [ *](.+)$/', $line, $matches) !== 1) {
                throw InvalidManifest::because($directory, "{$checksumsFile} has a malformed line");
            }
            $checksums[(string) preg_replace('#^\./#', '', $matches[2])] = $matches[1];
        }

        return $checksums;
    }

    private function isRelativePath(string $path): bool
    {
        return $path !== ''
            && ! str_starts_with($path, '/')
            && ! in_array('..', explode('/', $path), true);
    }
}
