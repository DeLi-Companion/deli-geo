<?php

declare(strict_types=1);

namespace DeLiCompanion\Geo\Loading;

final readonly class LoadReport
{
    public function __construct(
        public string $buildId,
        public int $featuresRead,
        public int $featuresSkipped,
        public int $addressesLoaded,
        public int $streetsLoaded,
        public int $postcodeAreasLoaded,
    ) {}
}
