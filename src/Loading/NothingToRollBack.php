<?php

declare(strict_types=1);

namespace DeLiCompanion\Geo\Loading;

use RuntimeException;

final class NothingToRollBack extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('There is no previous geo build to roll back to.');
    }
}
