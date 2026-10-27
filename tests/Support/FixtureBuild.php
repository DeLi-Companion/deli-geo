<?php

declare(strict_types=1);

namespace DeLiCompanion\Geo\Tests\Support;

use DeLiCompanion\Geo\Manifest\FormatVersion;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

final class FixtureBuild
{
    public static function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir().'/deli-geo-tests-'.bin2hex(random_bytes(6));
        mkdir($directory, 0o775, true);

        return $directory;
    }

    public static function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            assert($entry instanceof SplFileInfo);
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($directory);
    }

    /**
     * @param  list<string>  $addressLines
     * @param  list<string>  $postcodeAreaLines
     * @param  array<string, mixed>  $manifestOverrides
     */
    public static function create(
        string $buildsDirectory,
        string $buildId,
        array $addressLines,
        array $postcodeAreaLines,
        array $manifestOverrides = [],
    ): string {
        $directory = $buildsDirectory.'/'.$buildId;
        mkdir($directory, 0o775, true);

        self::writeGzip($directory.'/addresses.geojsonseq.gz', $addressLines);
        self::writeGzip($directory.'/postcode-areas.geojsonseq.gz', $postcodeAreaLines);

        $checksums = '';
        foreach (['addresses.geojsonseq.gz', 'postcode-areas.geojsonseq.gz'] as $file) {
            $checksums .= hash_file('sha256', $directory.'/'.$file).'  '.$file."\n";
        }
        file_put_contents($directory.'/SHA256SUMS', $checksums);

        $manifest = array_replace([
            'format_version' => FormatVersion::CURRENT,
            'build_id' => $buildId,
            'created_at' => '2026-10-27T10:00:00Z',
            'regions' => [['name' => 'berlin', 'bbox' => [13.0883, 52.3382, 13.7611, 52.6755]]],
            'sources' => [],
            'files' => [
                'addresses' => 'addresses.geojsonseq.gz',
                'postcode_areas' => 'postcode-areas.geojsonseq.gz',
            ],
            'checksums_file' => 'SHA256SUMS',
        ], $manifestOverrides);

        file_put_contents(
            $directory.'/manifest.json',
            json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
        );

        return $directory;
    }

    /**
     * @return list<string>
     */
    public static function lines(string $fixture): array
    {
        $contents = file_get_contents(__DIR__.'/../Fixtures/'.$fixture.'.geojsonseq');

        if ($contents === false) {
            throw new RuntimeException("Fixture {$fixture} is missing.");
        }

        return array_values(array_filter(
            explode("\n", $contents),
            static fn (string $line): bool => trim($line) !== '',
        ));
    }

    public static function addressLine(
        string $streetName,
        string $houseNumber,
        string $postalCode,
        float $longitude,
        float $latitude,
    ): string {
        return json_encode([
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [$longitude, $latitude]],
            'properties' => [
                'addr:street' => $streetName,
                'addr:housenumber' => $houseNumber,
                'addr:postcode' => $postalCode,
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @param  list<string>  $lines
     */
    private static function writeGzip(string $path, array $lines): void
    {
        $compressed = gzencode($lines === [] ? '' : implode("\n", $lines)."\n");

        if ($compressed === false) {
            throw new RuntimeException("Cannot compress {$path}.");
        }

        file_put_contents($path, $compressed);
    }
}
