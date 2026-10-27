<?php

declare(strict_types=1);

namespace DeLiCompanion\Geo\Manifest;

use RuntimeException;

final class InvalidManifest extends RuntimeException
{
    public static function because(string $buildDirectory, string $reason): self
    {
        return new self("Invalid geo build in {$buildDirectory}: {$reason}.");
    }
}
