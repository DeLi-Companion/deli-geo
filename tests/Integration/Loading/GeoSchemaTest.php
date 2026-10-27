<?php

declare(strict_types=1);

use DeLiCompanion\Geo\Loading\GeoSchema;
use DeLiCompanion\Geo\Tests\Support\TestDatabase;

beforeEach(function (): void {
    $this->connection = TestDatabase::fresh();
    $this->schema = new GeoSchema($this->connection);
});

it('creates the live tables with their indexes, repeatedly', function (): void {
    $this->schema->createLiveTables();
    $this->schema->createLiveTables();

    expect(TestDatabase::exists($this->connection, 'geo_build_state'))->toBeTrue()
        ->and(TestDatabase::indexNames($this->connection, 'geo_addresses'))
        ->toBe(['geo_addresses_location_index', 'geo_addresses_lookup_index'])
        ->and(TestDatabase::indexNames($this->connection, 'geo_streets'))
        ->toBe(['geo_streets_lookup_index', 'geo_streets_street_name_trigram_index'])
        ->and($this->schema->tablesExist(''))->toBeTrue()
        ->and($this->schema->tablesExist('_next'))->toBeFalse();
});

it('renames tables together with their indexes', function (): void {
    $this->schema->createTables('_next');
    $this->schema->createIndexes('_next');

    $this->schema->renameTables('_next', '');

    expect($this->schema->tablesExist('_next'))->toBeFalse()
        ->and($this->schema->tablesExist(''))->toBeTrue()
        ->and(TestDatabase::indexNames($this->connection, 'geo_addresses'))
        ->toBe(['geo_addresses_location_index', 'geo_addresses_lookup_index'])
        ->and(TestDatabase::indexNames($this->connection, 'geo_streets'))
        ->toBe(['geo_streets_lookup_index', 'geo_streets_street_name_trigram_index']);
});

it('drops suffixed tables', function (): void {
    $this->schema->createTables('_previous');

    $this->schema->dropTables('_previous');

    expect($this->schema->tablesExist('_previous'))->toBeFalse();
});

it('refuses unknown table suffixes', function (string $suffix): void {
    $this->schema->dropTables($suffix);
})->with(['_old', '; DROP TABLE geo_addresses', 'next'])->throws(InvalidArgumentException::class);
