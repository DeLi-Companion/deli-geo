<?php

declare(strict_types=1);

namespace DeLiCompanion\Geo\Normalization;

final class HouseNumberParser
{
    private const string PATTERN = '/^\s*0*(\d{1,5}+)(?!\d)\s*([a-z](?![a-z]))?(?:\s*([-\/])\s*(\d{1,5}+[a-z]?+)(?![a-z\d]))?/u';

    public function parse(string $houseNumber): ?HouseNumber
    {
        if (preg_match(self::PATTERN, mb_strtolower($houseNumber), $matches) !== 1) {
            return null;
        }

        $number = (int) $matches[1];

        if ($number === 0) {
            return null;
        }

        $letter = $matches[2] ?? '';
        $continuation = isset($matches[4]) ? $matches[3].$matches[4] : '';

        return new HouseNumber($number, $letter.$continuation);
    }
}
