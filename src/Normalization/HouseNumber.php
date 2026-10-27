<?php

declare(strict_types=1);

namespace DeLiCompanion\Geo\Normalization;

final readonly class HouseNumber
{
    public function __construct(
        public int $number,
        public string $suffix,
    ) {}

    public function normalized(): string
    {
        return $this->number.$this->suffix;
    }
}
