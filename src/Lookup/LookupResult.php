<?php

declare(strict_types=1);

namespace DeLiCompanion\Geo\Lookup;

final readonly class LookupResult
{
    public function __construct(
        public MatchKind $kind,
        public ?float $latitude,
        public ?float $longitude,
        public ?string $streetName,
        public ?string $houseNumber,
        public ?float $similarity,
    ) {}

    public static function noMatch(): self
    {
        return new self(MatchKind::NoMatch, null, null, null, null, null);
    }

    public function needsReview(): bool
    {
        return $this->kind !== MatchKind::Exact;
    }
}
