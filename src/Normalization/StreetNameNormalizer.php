<?php

declare(strict_types=1);

namespace DeLiCompanion\Geo\Normalization;

use Normalizer;

final class StreetNameNormalizer
{
    public function normalize(string $streetName): string
    {
        $composed = Normalizer::normalize($streetName, Normalizer::FORM_C);
        $normalized = mb_strtolower(is_string($composed) ? $composed : $streetName);
        $normalized = strtr($normalized, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss']);
        $normalized = (string) preg_replace('/strasse(?!\p{L})/u', 'str', $normalized);

        return (string) preg_replace('/[^\p{L}\p{N}]+/u', '', $normalized);
    }
}
