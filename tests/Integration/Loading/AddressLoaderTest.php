<?php

declare(strict_types=1);

use DeLiCompanion\Geo\Loading\AddressLoader;
use DeLiCompanion\Geo\Loading\GeoSchema;
use DeLiCompanion\Geo\Loading\LoadInProgress;
use DeLiCompanion\Geo\Loading\LoadRejected;
use DeLiCompanion\Geo\Manifest\ChecksumMismatch;
use DeLiCompanion\Geo\Manifest\ManifestReader;
use DeLiCompanion\Geo\Tests\Support\FixtureBuild;
use DeLiCompanion\Geo\Tests\Support\TestDatabase;
use Pdo\Pgsql;

beforeEach(function (): void {
    $this->connection = TestDatabase::fresh();
    (new GeoSchema($this->connection))->createLiveTables();
    $this->buildsDirectory = FixtureBuild::temporaryDirectory();
    $this->loader = new AddressLoader($this->connection);
    $this->build = fn (string $buildId, ?array $addressLines = null, ?array $postcodeAreaLines = null): mixed => (new ManifestReader)->read(FixtureBuild::create(
        $this->buildsDirectory,
        $buildId,
        $addressLines ?? FixtureBuild::lines('addresses'),
        $postcodeAreaLines ?? FixtureBuild::lines('postcode-areas'),
    ));
});

afterEach(function (): void {
    FixtureBuild::removeDirectory($this->buildsDirectory);
});

function expectNoWorkTables(Pgsql $connection): void
{
    foreach (['geo_addresses_next', 'geo_streets_next', 'geo_address_staging', 'geo_address_located', 'geo_postcode_area_staging'] as $table) {
        expect(TestDatabase::exists($connection, $table))->toBeFalse();
    }
}

it('loads a build into the next tables and reports what it read', function (): void {
    $report = $this->loader->load(($this->build)('2026-10-27'));

    expect($report->buildId)->toBe('2026-10-27')
        ->and($report->featuresRead)->toBe(10)
        ->and($report->featuresSkipped)->toBe(2)
        ->and($report->addressesLoaded)->toBe(6)
        ->and($report->streetsLoaded)->toBe(5)
        ->and($report->postcodeAreasLoaded)->toBe(2)
        ->and(TestDatabase::count($this->connection, 'geo_addresses_next'))->toBe(6)
        ->and(TestDatabase::count($this->connection, 'geo_addresses'))->toBe(0)
        ->and(TestDatabase::exists($this->connection, 'geo_address_staging'))->toBeFalse()
        ->and(TestDatabase::indexNames($this->connection, 'geo_addresses_next'))
        ->toBe(['geo_addresses_next_location_index', 'geo_addresses_next_lookup_index']);
});

it('fills a missing postcode from the covering postcode area', function (): void {
    $this->loader->load(($this->build)('2026-10-27'));

    $postalCode = $this->connection
        ->query("SELECT postal_code FROM geo_addresses_next WHERE street_name = 'Chausseestraße' AND house_number = '125'")
        ->fetchColumn();

    expect($postalCode)->toBe('10115')
        ->and(TestDatabase::count($this->connection, "geo_addresses_next WHERE street_name = 'Waldweg'"))->toBe(0);
});

it('stores a building once, at a point inside the building', function (): void {
    $this->loader->load(($this->build)('2026-10-27'));

    $inside = $this->connection->query(<<<'SQL'
        SELECT count(*) FROM geo_addresses_next
        WHERE street_name_normalized = 'invalidenstr' AND house_number_normalized = '44'
          AND ST_Covers(ST_GeomFromText('POLYGON((13.3810 52.5300, 13.3816 52.5300, 13.3816 52.5304, 13.3810 52.5304, 13.3810 52.5300))', 4326), location)
    SQL)->fetchColumn();

    expect((int) $inside)->toBe(1)
        ->and(TestDatabase::count($this->connection, "geo_addresses_next WHERE street_name_normalized = 'invalidenstr' AND house_number_normalized = '44'"))->toBe(1);
});

it('merges the same address only when its points lie close together', function (float $secondLongitude, int $expectedAddresses): void {
    $this->loader->load(($this->build)('2026-10-27', [
        FixtureBuild::addressLine('Dorfstraße', '5', '99999', 11.0000, 51.0000),
        FixtureBuild::addressLine('Dorfstraße', '5', '99999', $secondLongitude, 51.0000),
    ]));

    expect(TestDatabase::count($this->connection, 'geo_addresses_next'))->toBe($expectedAddresses);
})->with([
    'two villages about 5 km apart' => [11.0700, 2],
    'two nodes of one address about 50 m apart' => [11.0007, 1],
]);

it('uses addr:place when there is no street and stores the normalized forms', function (): void {
    $this->loader->load(($this->build)('2026-10-27'));

    $rows = $this->connection
        ->query('SELECT street_name, street_name_normalized, house_number_normalized FROM geo_addresses_next ORDER BY street_name_normalized, house_number_normalized')
        ->fetchAll(PDO::FETCH_NUM);

    expect($rows)->toBe([
        ['Chausseestraße', 'chausseestr', '125'],
        ['Invalidenstraße', 'invalidenstr', '43'],
        ['Invalidenstraße', 'invalidenstr', '44'],
        ['Museumsinsel', 'museumsinsel', '1'],
        ['Rathausstraße', 'rathausstr', '15'],
        ['Torstraße', 'torstr', '12a'],
    ]);
});

it('derives one street per normalized name, postcode and place, located on one of its addresses', function (): void {
    $this->loader->load(($this->build)('2026-10-27'));

    $streetsOffAddresses = $this->connection->query(<<<'SQL'
        SELECT count(*) FROM geo_streets_next AS street
        WHERE NOT EXISTS (
            SELECT 1 FROM geo_addresses_next AS address
            WHERE address.postal_code = street.postal_code
              AND address.street_name_normalized = street.street_name_normalized
              AND ST_Equals(address.location, street.location)
        )
    SQL)->fetchColumn();

    expect(TestDatabase::count($this->connection, 'geo_streets_next'))->toBe(5)
        ->and((int) $streetsOffAddresses)->toBe(0);
});

it('derives one street per group of addresses when a street exists in two places of one postcode', function (): void {
    $this->loader->load(($this->build)('2026-10-27', [
        FixtureBuild::addressLine('Dorfstraße', '1', '99999', 11.0000, 51.0000),
        FixtureBuild::addressLine('Dorfstraße', '3', '99999', 11.0010, 51.0000),
        FixtureBuild::addressLine('Dorfstraße', '2', '99999', 11.0700, 51.0000),
        FixtureBuild::addressLine('Dorfstraße', '4', '99999', 11.0710, 51.0000),
    ]));

    $streetsPerVillage = $this->connection->query(<<<'SQL'
        SELECT count(*) FILTER (WHERE ST_X(location) < 11.01), count(*) FILTER (WHERE ST_X(location) > 11.06)
        FROM geo_streets_next
        WHERE postal_code = '99999' AND street_name_normalized = 'dorfstr'
    SQL)->fetch(PDO::FETCH_NUM);

    expect(TestDatabase::count($this->connection, 'geo_streets_next'))->toBe(2)
        ->and($streetsPerVillage)->toBe([1, 1]);
});

it('round-trips tabs, newlines and backslashes through COPY', function (): void {
    $line = json_encode([
        'type' => 'Feature',
        'geometry' => ['type' => 'Point', 'coordinates' => [13.38, 52.53]],
        'properties' => ['addr:street' => "Back\\slash\tWeg", 'addr:housenumber' => '1', 'addr:postcode' => '10115', 'addr:city' => "Ber\nlin"],
    ], JSON_THROW_ON_ERROR);

    $this->loader->load(($this->build)('2026-10-27', [$line]));

    expect($this->connection->query('SELECT street_name, city FROM geo_addresses_next')->fetch(PDO::FETCH_NUM))
        ->toBe(["Back\\slash\tWeg", "Ber\nlin"]);
});

it('refuses a build whose checksum does not match', function (): void {
    $manifest = ($this->build)('2026-10-27');
    file_put_contents($manifest->path('addresses'), 'changed', FILE_APPEND);

    expect(fn () => $this->loader->load($manifest))->toThrow(ChecksumMismatch::class);
    expectNoWorkTables($this->connection);
});

it('refuses a build with a malformed line and cleans up', function (): void {
    $manifest = ($this->build)('2026-10-27', [FixtureBuild::lines('addresses')[0], '{"type": "Feature",']);

    expect(fn () => $this->loader->load($manifest))->toThrow(JsonException::class);
    expectNoWorkTables($this->connection);
});

it('refuses a build without any usable address', function (): void {
    $manifest = ($this->build)('2026-10-27', [FixtureBuild::lines('addresses')[7]]);

    expect(fn () => $this->loader->load($manifest))->toThrow(LoadRejected::class, 'no usable address');
    expectNoWorkTables($this->connection);
});

it('refuses a build without any usable postcode area', function (array $postcodeAreaLines): void {
    $manifest = ($this->build)('2026-10-27', null, $postcodeAreaLines);

    expect(fn () => $this->loader->load($manifest))->toThrow(LoadRejected::class, 'no usable postcode area');
    expectNoWorkTables($this->connection);
})->with([
    'empty file' => [[]],
    'only unusable areas' => [[
        FixtureBuild::lines('postcode-areas')[2],
        '{"type":"Feature","geometry":{"type":"LineString","coordinates":[[13.50,52.50],[13.51,52.51]]},"properties":{"postal_code":"10117"}}',
    ]],
]);

it('refuses a build with fewer than half the live addresses', function (): void {
    $this->connection->exec(<<<'SQL'
        INSERT INTO geo_addresses (street_name, street_name_normalized, house_number, house_number_normalized, postal_code, location)
        SELECT 'Teststraße', 'teststr', n::text, n::text, '10115', ST_SetSRID(ST_MakePoint(13.38, 52.53), 4326)
        FROM generate_series(1, 100) AS n
    SQL);

    expect(fn () => $this->loader->load(($this->build)('2026-10-27')))->toThrow(LoadRejected::class, 'fewer');
    expect(TestDatabase::count($this->connection, 'geo_addresses'))->toBe(100);
    expectNoWorkTables($this->connection);
});

it('refuses to load while another load holds the lock', function (): void {
    $other = TestDatabase::connect();
    $other->exec('SELECT pg_advisory_lock('.AddressLoader::LOCK_KEY.')');

    try {
        expect(fn () => $this->loader->load(($this->build)('2026-10-27')))->toThrow(LoadInProgress::class);
    } finally {
        $other->exec('SELECT pg_advisory_unlock('.AddressLoader::LOCK_KEY.')');
    }
});
