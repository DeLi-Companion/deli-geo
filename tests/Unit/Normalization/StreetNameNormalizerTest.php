<?php

declare(strict_types=1);

use DeLiCompanion\Geo\Normalization\StreetNameNormalizer;

it('normalizes street name spellings to one form', function (string $streetName, string $expected): void {
    expect((new StreetNameNormalizer)->normalize($streetName))->toBe($expected);
})->with([
    'Straße' => ['Hauptstraße', 'hauptstr'],
    'Str. abbreviation' => ['Hauptstr.', 'hauptstr'],
    'Strasse spelling' => ['Hauptstrasse', 'hauptstr'],
    'separate street word' => ['Haupt Straße', 'hauptstr'],
    'hyphenated name' => ['Karl-Marx-Straße', 'karlmarxstr'],
    'spaces instead of hyphens' => ['Karl Marx Str.', 'karlmarxstr'],
    'upper case with umlaut' => ['ÄUSSERE STRASSE', 'aeusserestr'],
    'sharp s inside the name' => ['Großbeerenstraße', 'grossbeerenstr'],
    'decomposed umlaut' => ["Mu\u{0308}hlenstraße", 'muehlenstr'],
    'street word as name prefix' => ['Straßburger Straße', 'strassburgerstr'],
    'strasse inside a longer word' => ['Strassenbahnweg', 'strassenbahnweg'],
    'leading street word' => ['Straße des 17. Juni', 'strdes17juni'],
    'surrounding whitespace' => ['  Unter den Linden  ', 'unterdenlinden'],
    'place without street word' => ['Museumsinsel', 'museumsinsel'],
    'capital sharp s' => ['GROẞE HAMBURGER STRAẞE', 'grossehamburgerstr'],
]);

it('returns an empty string for names without letters or digits', function (): void {
    expect((new StreetNameNormalizer)->normalize(' - . '))->toBe('');
});
