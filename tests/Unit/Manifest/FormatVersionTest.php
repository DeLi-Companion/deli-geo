<?php

declare(strict_types=1);

use DeLiCompanion\Geo\Manifest\FormatVersion;

it('supports the current format version', function (): void {
    expect(FormatVersion::isSupported(FormatVersion::CURRENT))->toBeTrue();
});

it('rejects versions newer than the current one and versions below 1', function (int $version): void {
    expect(FormatVersion::isSupported($version))->toBeFalse();
})->with([FormatVersion::CURRENT + 1, 0, -1]);

it('lists the current version and the one before it, never below 1', function (): void {
    expect(FormatVersion::supported())->toBe(
        array_values(array_filter([FormatVersion::CURRENT, FormatVersion::CURRENT - 1], fn (int $version): bool => $version >= 1)),
    );
});
