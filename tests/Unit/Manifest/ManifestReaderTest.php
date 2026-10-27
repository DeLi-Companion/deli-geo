<?php

declare(strict_types=1);

use DeLiCompanion\Geo\Manifest\ChecksumMismatch;
use DeLiCompanion\Geo\Manifest\FormatVersion;
use DeLiCompanion\Geo\Manifest\InvalidManifest;
use DeLiCompanion\Geo\Manifest\ManifestReader;
use DeLiCompanion\Geo\Manifest\UnsupportedFormatVersion;
use DeLiCompanion\Geo\Tests\Support\FixtureBuild;

beforeEach(function (): void {
    $this->buildsDirectory = FixtureBuild::temporaryDirectory();
});

afterEach(function (): void {
    FixtureBuild::removeDirectory($this->buildsDirectory);
});

it('reads a valid build directory', function (): void {
    $directory = FixtureBuild::create($this->buildsDirectory, '2026-10-27', ['{}'], ['{}']);

    $manifest = (new ManifestReader)->read($directory);

    expect($manifest->formatVersion)->toBe(FormatVersion::CURRENT)
        ->and($manifest->buildId)->toBe('2026-10-27')
        ->and($manifest->createdAt->format(DATE_ATOM))->toBe('2026-10-27T10:00:00+00:00')
        ->and($manifest->files)->toBe([
            'addresses' => 'addresses.geojsonseq.gz',
            'postcode_areas' => 'postcode-areas.geojsonseq.gz',
        ])
        ->and($manifest->checksums)->toHaveKeys(['addresses.geojsonseq.gz', 'postcode-areas.geojsonseq.gz'])
        ->and($manifest->path('addresses'))->toBe($directory.'/addresses.geojsonseq.gz');
});

it('returns the verified path when the checksum matches', function (): void {
    $directory = FixtureBuild::create($this->buildsDirectory, '2026-10-27', ['{}'], ['{}']);

    expect((new ManifestReader)->read($directory)->verifiedPath('addresses'))
        ->toBe($directory.'/addresses.geojsonseq.gz');
});

it('refuses a file whose checksum no longer matches', function (): void {
    $directory = FixtureBuild::create($this->buildsDirectory, '2026-10-27', ['{}'], ['{}']);
    file_put_contents($directory.'/addresses.geojsonseq.gz', 'changed', FILE_APPEND);

    (new ManifestReader)->read($directory)->verifiedPath('addresses');
})->throws(ChecksumMismatch::class);

it('refuses a role the manifest does not list', function (): void {
    $directory = FixtureBuild::create($this->buildsDirectory, '2026-10-27', ['{}'], ['{}']);

    (new ManifestReader)->read($directory)->verifiedPath('map');
})->throws(InvalidManifest::class, 'files has no entry for map');

it('refuses a role that points to a directory', function (): void {
    $directory = FixtureBuild::create($this->buildsDirectory, '2026-10-27', ['{}'], ['{}'], [
        'files' => ['addresses' => 'addresses.geojsonseq.gz', 'map_fonts' => 'map/fonts'],
    ]);
    mkdir($directory.'/map/fonts', 0o775, true);

    (new ManifestReader)->read($directory)->verifiedPath('map_fonts');
})->throws(InvalidManifest::class, 'map/fonts is a directory, not a file');

it('refuses an unsupported format version', function (): void {
    $directory = FixtureBuild::create($this->buildsDirectory, '2026-10-27', ['{}'], ['{}'], [
        'format_version' => FormatVersion::CURRENT + 1,
    ]);

    (new ManifestReader)->read($directory);
})->throws(UnsupportedFormatVersion::class);

it('refuses invalid manifests', function (array $overrides, string $reason): void {
    $directory = FixtureBuild::create($this->buildsDirectory, '2026-10-27', ['{}'], ['{}'], $overrides);

    expect(fn () => (new ManifestReader)->read($directory))->toThrow(InvalidManifest::class, $reason);
})->with([
    'format version as string' => [['format_version' => '1'], 'format_version must be an integer'],
    'build id of another directory' => [['build_id' => '2026-10-26'], 'build_id does not match the directory name'],
    'build id that is no date' => [['build_id' => 'latest'], 'build_id must be a date like 2026-10-27'],
    'creation time without zone' => [['created_at' => '2026-10-27 10:00'], 'created_at must be a UTC timestamp like 2026-10-27T10:00:00Z'],
    'files missing' => [['files' => []], 'files must map roles to relative paths'],
    'absolute file path' => [['files' => ['addresses' => '/etc/passwd']], 'files must map roles to relative paths'],
    'path leaving the build' => [['files' => ['addresses' => '../other/addresses.geojsonseq.gz']], 'files must map roles to relative paths'],
    'checksums file missing' => [['checksums_file' => 'MISSING'], 'MISSING is missing'],
]);

it('refuses a directory without manifest', function (): void {
    mkdir($this->buildsDirectory.'/2026-10-27');

    (new ManifestReader)->read($this->buildsDirectory.'/2026-10-27');
})->throws(InvalidManifest::class, 'manifest.json is missing');

it('refuses a manifest that is not valid JSON', function (): void {
    $directory = FixtureBuild::create($this->buildsDirectory, '2026-10-27', ['{}'], ['{}']);
    file_put_contents($directory.'/manifest.json', '{"format_version": 1,');

    (new ManifestReader)->read($directory);
})->throws(InvalidManifest::class, 'manifest.json is not valid JSON');

it('refuses a malformed checksums file', function (): void {
    $directory = FixtureBuild::create($this->buildsDirectory, '2026-10-27', ['{}'], ['{}']);
    file_put_contents($directory.'/SHA256SUMS', "not-a-checksum  addresses.geojsonseq.gz\n");

    (new ManifestReader)->read($directory);
})->throws(InvalidManifest::class, 'SHA256SUMS has a malformed line');
