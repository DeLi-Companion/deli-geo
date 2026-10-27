<?php

declare(strict_types=1);

use DeLiCompanion\Geo\Loading\CopyEncoder;

it('joins values with tabs and writes null as \N', function (): void {
    expect(CopyEncoder::row(['Invalidenstraße', null, '43']))->toBe("Invalidenstraße\t\\N\t43");
});

it('escapes backslashes, tabs, newlines and carriage returns', function (): void {
    expect(CopyEncoder::row(["a\\b\tc\nd\re"]))->toBe('a\\\\b\\tc\\nd\\re');
});
