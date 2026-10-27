<?php

declare(strict_types=1);

use DeLiCompanion\Geo\Normalization\HouseNumberParser;

it('parses house numbers into number and suffix', function (string $houseNumber, int $number, string $suffix, string $normalized): void {
    $parsed = (new HouseNumberParser)->parse($houseNumber);

    expect($parsed)->not->toBeNull()
        ->and($parsed?->number)->toBe($number)
        ->and($parsed?->suffix)->toBe($suffix)
        ->and($parsed?->normalized())->toBe($normalized);
})->with([
    'plain number' => ['12', 12, '', '12'],
    'letter' => ['12a', 12, 'a', '12a'],
    'spaced capital letter' => ['12 A', 12, 'a', '12a'],
    'range' => ['12-14', 12, '-14', '12-14'],
    'spaced range' => ['12 - 14', 12, '-14', '12-14'],
    'slash' => ['12/1', 12, '/1', '12/1'],
    'letter and range' => ['12a-12c', 12, 'a-12c', '12a-12c'],
    'leading zeros' => ['007', 7, '', '7'],
    'trailing words' => ['12 Hinterhaus', 12, '', '12'],
    'floor after comma' => ['12, 2. OG', 12, '', '12'],
    'semicolon list' => ['12;14', 12, '', '12'],
    'surrounding whitespace' => [' 5b ', 5, 'b', '5b'],
    'range followed by words' => ['12-14abc', 12, '', '12'],
    'range with letter' => ['12-14a', 12, '-14a', '12-14a'],
]);

it('rejects values that are no house number', function (string $houseNumber): void {
    expect((new HouseNumberParser)->parse($houseNumber))->toBeNull();
})->with(['', '   ', 'ohne', 'o. Nr.', '0', 'Nr. 12', '123456', '0a', '00']);
