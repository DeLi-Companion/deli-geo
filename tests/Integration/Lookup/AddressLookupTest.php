<?php

declare(strict_types=1);

use DeLiCompanion\Geo\Loading\AddressLoader;
use DeLiCompanion\Geo\Loading\GeoSchema;
use DeLiCompanion\Geo\Lookup\AddressLookup;
use DeLiCompanion\Geo\Lookup\AddressQuery;
use DeLiCompanion\Geo\Lookup\MatchKind;
use DeLiCompanion\Geo\Manifest\ManifestReader;
use DeLiCompanion\Geo\Tests\Support\FixtureBuild;
use DeLiCompanion\Geo\Tests\Support\TestDatabase;

beforeEach(function (): void {
    $this->connection = TestDatabase::fresh();
    (new GeoSchema($this->connection))->createLiveTables();
    $this->buildsDirectory = FixtureBuild::temporaryDirectory();
    $this->lookup = new AddressLookup($this->connection);
});

afterEach(function (): void {
    FixtureBuild::removeDirectory($this->buildsDirectory);
});

/**
 * @param  list<string>|null  $addressLines
 */
function activateFixtureBuild(object $test, ?array $addressLines = null): void
{
    $manifest = (new ManifestReader)->read(FixtureBuild::create(
        $test->buildsDirectory,
        '2026-10-27',
        $addressLines ?? FixtureBuild::lines('addresses'),
        FixtureBuild::lines('postcode-areas'),
    ));
    $loader = new AddressLoader($test->connection);
    $loader->load($manifest);
    $loader->activate($manifest);
}

it('returns no match while the live tables are empty', function (): void {
    expect($this->lookup->find(new AddressQuery('10115', 'Invalidenstraße', '43'))->kind)->toBe(MatchKind::NoMatch);
});

it('accepts exact matches in every common spelling', function (string $postalCode, string $streetName, string $houseNumber, string $storedHouseNumber): void {
    activateFixtureBuild($this);

    $result = $this->lookup->find(new AddressQuery($postalCode, $streetName, $houseNumber));

    expect($result->kind)->toBe(MatchKind::Exact)
        ->and($result->needsReview())->toBeFalse()
        ->and($result->houseNumber)->toBe($storedHouseNumber)
        ->and($result->latitude)->not->toBeNull()
        ->and($result->longitude)->not->toBeNull();
})->with([
    'stored spelling' => ['10115', 'Invalidenstraße', '43', '43'],
    'abbreviation' => ['10115', 'Invalidenstr.', '43', '43'],
    'upper case and spaces' => [' 10115 ', 'INVALIDENSTRASSE', ' 43 ', '43'],
    'spaced suffix' => ['10119', 'Torstr.', '12 A', '12a'],
    'place instead of street' => ['10178', 'Museumsinsel', '1', '1'],
    'filled postcode' => ['10115', 'Chausseestraße', '125', '125'],
]);

it('returns the stored coordinates of an exact match', function (): void {
    activateFixtureBuild($this);

    $result = $this->lookup->find(new AddressQuery('10115', 'Invalidenstraße', '43'));

    expect($result->latitude)->toEqualWithDelta(52.5302, 0.00001)
        ->and($result->longitude)->toEqualWithDelta(13.3796, 0.00001)
        ->and($result->streetName)->toBe('Invalidenstraße');
});

it('finds a misspelled street as a fuzzy match that needs review', function (): void {
    activateFixtureBuild($this);

    $result = $this->lookup->find(new AddressQuery('10115', 'Invalidenstrase', '43'));

    expect($result->kind)->toBe(MatchKind::FuzzyStreet)
        ->and($result->needsReview())->toBeTrue()
        ->and($result->streetName)->toBe('Invalidenstraße')
        ->and($result->houseNumber)->toBe('43')
        ->and($result->similarity)->toBeGreaterThanOrEqual(0.6)->toBeLessThan(0.8);
});

it('honours a stricter similarity threshold', function (): void {
    activateFixtureBuild($this);

    $strictLookup = new AddressLookup($this->connection, similarityThreshold: 0.8);

    expect($strictLookup->find(new AddressQuery('10115', 'Invalidenstrase', '43'))->kind)->toBe(MatchKind::NoMatch);
});

it('falls back to the street centre when the house number is missing or unknown', function (?string $houseNumber): void {
    activateFixtureBuild($this);

    $result = $this->lookup->find(new AddressQuery('10119', 'Torstraße', $houseNumber));

    expect($result->kind)->toBe(MatchKind::StreetCentre)
        ->and($result->needsReview())->toBeTrue()
        ->and($result->streetName)->toBe('Torstraße')
        ->and($result->houseNumber)->toBeNull()
        ->and($result->latitude)->toEqualWithDelta(52.5293, 0.00001);
})->with(['missing' => [null], 'not in the data' => ['12'], 'unparseable' => ['ohne']]);

it('places a misspelled street without house number at the street centre', function (): void {
    activateFixtureBuild($this);

    $result = $this->lookup->find(new AddressQuery('10115', 'Invalidenstrase', null));

    expect($result->kind)->toBe(MatchKind::StreetCentre)
        ->and($result->streetName)->toBe('Invalidenstraße')
        ->and($result->similarity)->toBeLessThan(1.0);
});

it('returns no match for unknown streets and invalid input', function (string $postalCode, string $streetName): void {
    activateFixtureBuild($this);

    $result = $this->lookup->find(new AddressQuery($postalCode, $streetName, '1'));

    expect($result->kind)->toBe(MatchKind::NoMatch)
        ->and($result->needsReview())->toBeTrue()
        ->and($result->latitude)->toBeNull()
        ->and($result->longitude)->toBeNull();
})->with([
    'unknown street' => ['10115', 'Bahnhofstraße'],
    'street in another postcode' => ['10119', 'Invalidenstraße'],
    'invalid postal code' => ['101', 'Invalidenstraße'],
    'empty street' => ['10115', ' - '],
]);

it('reports an address that exists in two places of one postcode as ambiguous', function (): void {
    activateFixtureBuild($this, twoVillagesWithOneStreetName());

    $result = $this->lookup->find(new AddressQuery('99999', 'Dorfstraße', '5'));

    expect($result->kind)->toBe(MatchKind::Ambiguous)
        ->and($result->needsReview())->toBeTrue()
        ->and($result->streetName)->toBe('Dorfstraße')
        ->and($result->houseNumber)->toBe('5')
        ->and($result->similarity)->toBe(1.0)
        ->and($result->latitude)->toEqualWithDelta(50.9900, 0.00001)
        ->and($result->longitude)->toEqualWithDelta(11.0700, 0.00001);
});

it('reports a street that exists in two places of one postcode as ambiguous without an address hit', function (?string $houseNumber): void {
    activateFixtureBuild($this, twoVillagesWithOneStreetName());

    $result = $this->lookup->find(new AddressQuery('99999', 'Dorfstraße', $houseNumber));

    expect($result->kind)->toBe(MatchKind::Ambiguous)
        ->and($result->needsReview())->toBeTrue()
        ->and($result->streetName)->toBe('Dorfstraße')
        ->and($result->houseNumber)->toBeNull()
        ->and($result->latitude)->toEqualWithDelta(50.9900, 0.00001);
})->with(['missing' => [null], 'not in the data' => ['9']]);

it('still finds an address that exists only once on a street in two places', function (): void {
    activateFixtureBuild($this, twoVillagesWithOneStreetName());

    $result = $this->lookup->find(new AddressQuery('99999', 'Dorfstraße', '7'));

    expect($result->kind)->toBe(MatchKind::Exact)
        ->and($result->needsReview())->toBeFalse()
        ->and($result->houseNumber)->toBe('7')
        ->and($result->longitude)->toEqualWithDelta(11.0010, 0.00001);
});

it('restores the leading zero of a four-digit postal code', function (): void {
    activateFixtureBuild($this, [FixtureBuild::addressLine('Postplatz', '1', '01067', 13.7335, 51.0505)]);

    $result = $this->lookup->find(new AddressQuery('1067', 'Postplatz', '1'));

    expect($result->kind)->toBe(MatchKind::Exact)
        ->and($result->latitude)->toEqualWithDelta(51.0505, 0.00001);
});

/**
 * @return list<string>
 */
function twoVillagesWithOneStreetName(): array
{
    return [
        FixtureBuild::addressLine('Dorfstraße', '5', '99999', 11.0000, 51.0000),
        FixtureBuild::addressLine('Dorfstraße', '7', '99999', 11.0010, 51.0000),
        FixtureBuild::addressLine('Dorfstraße', '5', '99999', 11.0700, 50.9900),
    ];
}
