<?php

declare(strict_types=1);

use DeLiCompanion\Geo\Loading\AddressLoader;
use DeLiCompanion\Geo\Loading\GeoSchema;
use DeLiCompanion\Geo\Lookup\AddressLookup;
use DeLiCompanion\Geo\Lookup\AddressQuery;
use DeLiCompanion\Geo\Lookup\MatchKind;
use DeLiCompanion\Geo\Manifest\FormatVersion;
use DeLiCompanion\Geo\Manifest\ManifestReader;
use DeLiCompanion\Geo\Tests\Support\TestDatabase;
use PHPUnit\Framework\Assert;

it('loads a real geo build and finds public Berlin addresses', function (): void {
    $buildDirectory = getenv('DELI_GEO_BUILD_DIRECTORY');

    if (! is_string($buildDirectory) || $buildDirectory === '') {
        Assert::markTestSkipped('Set DELI_GEO_BUILD_DIRECTORY to a published geo build to run this test.');
    }

    $connection = TestDatabase::fresh();
    (new GeoSchema($connection))->createLiveTables();
    $manifest = (new ManifestReader)->read($buildDirectory);
    $loader = new AddressLoader($connection);

    $report = $loader->load($manifest);
    $loader->activate($manifest);

    expect($manifest->formatVersion)->toBe(FormatVersion::CURRENT)
        ->and($report->addressesLoaded)->toBeGreaterThan(100_000)
        ->and($report->streetsLoaded)->toBeGreaterThan(5_000)
        ->and($report->postcodeAreasLoaded)->toBeGreaterThan(100)
        ->and($loader->currentBuildId())->toBe($manifest->buildId);

    $lookup = new AddressLookup($connection);

    foreach ([
        ['10115', 'Invalidenstraße', '43'],
        ['10117', 'Unter den Linden', '6'],
        ['10178', 'Rathausstraße', '15'],
    ] as [$postalCode, $streetName, $houseNumber]) {
        expect($lookup->find(new AddressQuery($postalCode, $streetName, $houseNumber))->kind)
            ->toBe(MatchKind::Exact, "{$streetName} {$houseNumber}, {$postalCode}");
    }

    foreach (['map', 'valhalla_tiles', 'valhalla_config', 'valhalla_admins'] as $role) {
        expect($manifest->verifiedPath($role))->toBeFile();
    }
});
