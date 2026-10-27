<?php

declare(strict_types=1);

namespace DeLiCompanion\Geo\Manifest;

use RuntimeException;

final class UnsupportedFormatVersion extends RuntimeException
{
    public function __construct(
        public readonly string $buildDirectory,
        public readonly int $formatVersion,
    ) {
        parent::__construct(sprintf(
            'Geo build %s has format_version %d; this library reads %s.',
            $buildDirectory,
            $formatVersion,
            implode(' and ', FormatVersion::supported()),
        ));
    }
}
