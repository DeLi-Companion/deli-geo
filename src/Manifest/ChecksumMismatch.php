<?php

declare(strict_types=1);

namespace DeLiCompanion\Geo\Manifest;

use RuntimeException;

final class ChecksumMismatch extends RuntimeException
{
    public function __construct(string $path, string $expected, string $actual)
    {
        parent::__construct("Checksum mismatch for {$path}: expected {$expected}, got {$actual}.");
    }
}
