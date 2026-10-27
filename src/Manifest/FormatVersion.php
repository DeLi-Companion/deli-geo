<?php

declare(strict_types=1);

namespace DeLiCompanion\Geo\Manifest;

final class FormatVersion
{
    public const int CURRENT = 1;

    /**
     * @return list<int>
     */
    public static function supported(): array
    {
        return array_filter(
            [self::CURRENT, self::CURRENT - 1],
            static fn (int $version): bool => $version >= 1,
        );
    }

    public static function isSupported(int $formatVersion): bool
    {
        return in_array($formatVersion, self::supported(), true);
    }
}
