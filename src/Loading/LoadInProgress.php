<?php

declare(strict_types=1);

namespace DeLiCompanion\Geo\Loading;

use RuntimeException;

final class LoadInProgress extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Another geo load, activation or rollback is running.');
    }
}
