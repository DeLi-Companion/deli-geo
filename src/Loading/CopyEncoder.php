<?php

declare(strict_types=1);

namespace DeLiCompanion\Geo\Loading;

final class CopyEncoder
{
    /**
     * @param  list<string|null>  $values
     */
    public static function row(array $values): string
    {
        return implode("\t", array_map(
            static fn (?string $value): string => $value === null
                ? '\N'
                : strtr($value, ['\\' => '\\\\', "\t" => '\t', "\n" => '\n', "\r" => '\r']),
            $values,
        ));
    }
}
