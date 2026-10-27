<?php

declare(strict_types=1);

use DeLiCompanion\Geo\Loading\AddressLoader;
use DeLiCompanion\Geo\Loading\GeoSchema;
use DeLiCompanion\Geo\Loading\LoadRejected;
use DeLiCompanion\Geo\Loading\NothingToRollBack;
use DeLiCompanion\Geo\Manifest\Manifest;
use DeLiCompanion\Geo\Manifest\ManifestReader;
use DeLiCompanion\Geo\Tests\Support\FixtureBuild;
use DeLiCompanion\Geo\Tests\Support\TestDatabase;

beforeEach(function (): void {
    $this->connection = TestDatabase::fresh();
    (new GeoSchema($this->connection))->createLiveTables();
    $this->buildsDirectory = FixtureBuild::temporaryDirectory();
    $this->loader = new AddressLoader($this->connection);
    $this->build = function (string $buildId, bool $withoutRathaus = false): Manifest {
        $lines = FixtureBuild::lines('addresses');

        if ($withoutRathaus) {
            unset($lines[5]);
        }

        return (new ManifestReader)->read(FixtureBuild::create(
            $this->buildsDirectory,
            $buildId,
            array_values($lines),
            FixtureBuild::lines('postcode-areas'),
        ));
    };
    $this->loadAndActivate = function (Manifest $manifest): void {
        $this->loader->load($manifest);
        $this->loader->activate($manifest);
    };
});

afterEach(function (): void {
    FixtureBuild::removeDirectory($this->buildsDirectory);
});

it('returns no build id before any activation', function (): void {
    expect($this->loader->currentBuildId())->toBeNull();
});

it('makes a loaded build live', function (): void {
    ($this->loadAndActivate)(($this->build)('2026-10-27'));

    expect(TestDatabase::count($this->connection, 'geo_addresses'))->toBe(6)
        ->and(TestDatabase::count($this->connection, 'geo_streets'))->toBe(5)
        ->and(TestDatabase::exists($this->connection, 'geo_addresses_next'))->toBeFalse()
        ->and(TestDatabase::indexNames($this->connection, 'geo_addresses'))
        ->toBe(['geo_addresses_location_index', 'geo_addresses_lookup_index'])
        ->and(TestDatabase::indexNames($this->connection, 'geo_streets'))
        ->toBe(['geo_streets_lookup_index', 'geo_streets_street_name_trigram_index'])
        ->and($this->loader->currentBuildId())->toBe('2026-10-27');
});

it('keeps the previous build and drops older ones', function (): void {
    ($this->loadAndActivate)(($this->build)('2026-10-27'));
    ($this->loadAndActivate)(($this->build)('2026-10-28', withoutRathaus: true));

    expect(TestDatabase::count($this->connection, 'geo_addresses'))->toBe(5)
        ->and(TestDatabase::count($this->connection, 'geo_addresses_previous'))->toBe(6)
        ->and(TestDatabase::indexNames($this->connection, 'geo_addresses_previous'))
        ->toBe(['geo_addresses_previous_location_index', 'geo_addresses_previous_lookup_index']);

    ($this->loadAndActivate)(($this->build)('2026-10-29'));

    expect(TestDatabase::count($this->connection, 'geo_addresses'))->toBe(6)
        ->and(TestDatabase::count($this->connection, 'geo_addresses_previous'))->toBe(5)
        ->and($this->loader->currentBuildId())->toBe('2026-10-29');
});

it('refuses to activate a build that was not loaded', function (): void {
    $this->loader->load(($this->build)('2026-10-27'));

    expect(fn () => $this->loader->activate(($this->build)('2026-10-28')))
        ->toThrow(LoadRejected::class, 'not loaded');
    expect(TestDatabase::count($this->connection, 'geo_addresses'))->toBe(0);
});

it('rolls back to the previous build', function (): void {
    ($this->loadAndActivate)(($this->build)('2026-10-27'));
    ($this->loadAndActivate)(($this->build)('2026-10-28', withoutRathaus: true));

    $this->loader->rollback();

    expect(TestDatabase::count($this->connection, 'geo_addresses'))->toBe(6)
        ->and(TestDatabase::exists($this->connection, 'geo_addresses_previous'))->toBeFalse()
        ->and(TestDatabase::indexNames($this->connection, 'geo_addresses'))
        ->toBe(['geo_addresses_location_index', 'geo_addresses_lookup_index'])
        ->and($this->loader->currentBuildId())->toBe('2026-10-27');

    expect(fn () => $this->loader->rollback())->toThrow(NothingToRollBack::class);
});

it('refuses to roll back the first activation', function (): void {
    // Regression: the empty tables from createLiveTables() were kept as "previous", so rolling back the first build produced an empty live database.
    ($this->loadAndActivate)(($this->build)('2026-10-27'));

    expect(fn () => $this->loader->rollback())->toThrow(NothingToRollBack::class);
    expect(TestDatabase::count($this->connection, 'geo_addresses'))->toBe(6)
        ->and(TestDatabase::exists($this->connection, 'geo_addresses_previous'))->toBeFalse()
        ->and($this->loader->currentBuildId())->toBe('2026-10-27');
});
