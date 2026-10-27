<?php

declare(strict_types=1);

namespace DeLiCompanion\Geo\Lookup;

final readonly class AddressQuery
{
    public function __construct(
        public string $postalCode,
        public string $streetName,
        public ?string $houseNumber,
    ) {}
}
