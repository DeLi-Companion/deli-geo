<?php

declare(strict_types=1);

namespace DeLiCompanion\Geo\Manifest;

final readonly class BuildFinder
{
    public function __construct(
        private ManifestReader $manifestReader = new ManifestReader,
    ) {}

    public function newest(string $buildsDirectory): ?Manifest
    {
        if (! is_dir($buildsDirectory)) {
            return null;
        }

        $buildIds = array_values(array_filter(
            scandir($buildsDirectory) ?: [],
            static fn (string $entry): bool => preg_match('/^\d{4}-\d{2}-\d{2}$/', $entry) === 1
                && is_dir($buildsDirectory.'/'.$entry),
        ));
        rsort($buildIds);

        foreach ($buildIds as $buildId) {
            try {
                return $this->manifestReader->read($buildsDirectory.'/'.$buildId);
            } catch (InvalidManifest|UnsupportedFormatVersion) {
                continue;
            }
        }

        return null;
    }
}
