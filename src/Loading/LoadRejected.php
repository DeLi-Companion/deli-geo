<?php

declare(strict_types=1);

namespace DeLiCompanion\Geo\Loading;

use RuntimeException;

final class LoadRejected extends RuntimeException
{
    public static function noAddresses(): self
    {
        return new self('The geo build contains no usable address; the live tables stay unchanged.');
    }

    public static function noPostcodeAreas(): self
    {
        return new self('The geo build contains no usable postcode area; the live tables stay unchanged.');
    }

    public static function tooFewAddresses(int $loaded, int $live, float $minimumRatio): self
    {
        return new self(sprintf(
            'The geo build has %d addresses, fewer than %d%% of the %d live addresses; the live tables stay unchanged.',
            $loaded,
            (int) round($minimumRatio * 100),
            $live,
        ));
    }

    public static function notLoaded(string $buildId): self
    {
        return new self("Geo build {$buildId} is not loaded; load it before activating it.");
    }
}
