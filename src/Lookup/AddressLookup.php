<?php

declare(strict_types=1);

namespace DeLiCompanion\Geo\Lookup;

use DeLiCompanion\Geo\Normalization\HouseNumberParser;
use DeLiCompanion\Geo\Normalization\StreetNameNormalizer;
use DeLiCompanion\Geo\Support\Query;
use Pdo\Pgsql;

final readonly class AddressLookup
{
    private const string STREETS_SQL = <<<'SQL'
        SELECT street_name, ST_Y(location) AS latitude, ST_X(location) AS longitude
        FROM geo_streets
        WHERE postal_code = :postal_code AND street_name_normalized = :street_name_normalized
        ORDER BY latitude, longitude
        LIMIT 2
    SQL;

    private const string SIMILAR_STREET_NAME_SQL = <<<'SQL'
        SELECT street_name_normalized, similarity
        FROM (
            SELECT street_name_normalized, similarity(street_name_normalized, :street_name_normalized) AS similarity
            FROM geo_streets
            WHERE postal_code = :postal_code
        ) AS candidate
        WHERE similarity >= :threshold
        ORDER BY similarity DESC, street_name_normalized
        LIMIT 1
    SQL;

    private const string ADDRESSES_SQL = <<<'SQL'
        SELECT street_name, house_number, ST_Y(location) AS latitude, ST_X(location) AS longitude
        FROM geo_addresses
        WHERE postal_code = :postal_code
          AND street_name_normalized = :street_name_normalized
          AND house_number_normalized = :house_number_normalized
        ORDER BY latitude, longitude
        LIMIT 2
    SQL;

    public function __construct(
        private Pgsql $connection,
        private StreetNameNormalizer $streetNameNormalizer = new StreetNameNormalizer,
        private HouseNumberParser $houseNumberParser = new HouseNumberParser,
        private float $similarityThreshold = 0.6,
    ) {}

    public function find(AddressQuery $query): LookupResult
    {
        $postalCode = $this->postalCode($query->postalCode);
        $streetNameNormalized = $this->streetNameNormalizer->normalize($query->streetName);

        if ($postalCode === null || $streetNameNormalized === '') {
            return LookupResult::noMatch();
        }

        $kind = MatchKind::Exact;
        $similarity = 1.0;
        $streets = $this->streets($postalCode, $streetNameNormalized);

        if ($streets === []) {
            $similarStreet = Query::row($this->connection, self::SIMILAR_STREET_NAME_SQL, [
                'postal_code' => $postalCode,
                'street_name_normalized' => $streetNameNormalized,
                'threshold' => $this->similarityThreshold,
            ]);

            if ($similarStreet !== null) {
                $kind = MatchKind::FuzzyStreet;
                $similarity = (float) $similarStreet['similarity'];
                $streetNameNormalized = (string) $similarStreet['street_name_normalized'];
                $streets = $this->streets($postalCode, $streetNameNormalized);
            }
        }

        if ($streets === []) {
            return LookupResult::noMatch();
        }

        $houseNumber = $query->houseNumber === null ? null : $this->houseNumberParser->parse($query->houseNumber);
        $addresses = $houseNumber === null ? [] : Query::rows($this->connection, self::ADDRESSES_SQL, [
            'postal_code' => $postalCode,
            'street_name_normalized' => $streetNameNormalized,
            'house_number_normalized' => $houseNumber->normalized(),
        ]);

        if ($addresses !== []) {
            return new LookupResult(
                count($addresses) === 1 ? $kind : MatchKind::Ambiguous,
                (float) $addresses[0]['latitude'],
                (float) $addresses[0]['longitude'],
                (string) $addresses[0]['street_name'],
                (string) $addresses[0]['house_number'],
                $similarity,
            );
        }

        return new LookupResult(
            count($streets) === 1 ? MatchKind::StreetCentre : MatchKind::Ambiguous,
            (float) $streets[0]['latitude'],
            (float) $streets[0]['longitude'],
            (string) $streets[0]['street_name'],
            null,
            $similarity,
        );
    }

    private function postalCode(string $postalCode): ?string
    {
        $trimmed = trim($postalCode);

        if (preg_match('/^\d{4}$/', $trimmed) === 1) {
            $trimmed = '0'.$trimmed;
        }

        return preg_match('/^\d{5}$/', $trimmed) === 1 ? $trimmed : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function streets(string $postalCode, string $streetNameNormalized): array
    {
        return Query::rows($this->connection, self::STREETS_SQL, [
            'postal_code' => $postalCode,
            'street_name_normalized' => $streetNameNormalized,
        ]);
    }
}
