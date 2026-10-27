<?php

declare(strict_types=1);

namespace DeLiCompanion\Geo\Loading;

use DeLiCompanion\Geo\Manifest\Manifest;
use DeLiCompanion\Geo\Normalization\HouseNumberParser;
use DeLiCompanion\Geo\Normalization\StreetNameNormalizer;
use DeLiCompanion\Geo\Support\Query;
use Pdo\Pgsql;
use RuntimeException;
use Throwable;

final readonly class AddressLoader
{
    public const int LOCK_KEY = 4_731_020_017;

    private const int BATCH_SIZE = 5_000;

    private const string ADDRESS_COLUMNS = 'street_name, street_name_normalized, house_number, house_number_normalized, postal_code, city, geometry';

    private const string POSTCODE_AREA_COLUMNS = 'postal_code, geometry';

    private const string CREATE_STAGING_SQL = <<<'SQL'
        CREATE UNLOGGED TABLE geo_address_staging (
            street_name text NOT NULL,
            street_name_normalized text NOT NULL,
            house_number text NOT NULL,
            house_number_normalized text NOT NULL,
            postal_code text,
            city text,
            geometry text NOT NULL
        );
        CREATE UNLOGGED TABLE geo_postcode_area_staging (
            postal_code text NOT NULL,
            geometry text NOT NULL
        );
    SQL;

    private const float ADDRESS_CLUSTER_DISTANCE_DEGREES = 0.003;

    private const float STREET_CLUSTER_DISTANCE_DEGREES = 0.01;

    private const string PREPARE_POSTCODE_AREAS_SQL = <<<'SQL'
        ALTER TABLE geo_postcode_area_staging ADD COLUMN area geometry(MultiPolygon, 4326);
        UPDATE geo_postcode_area_staging
            SET area = ST_Multi(ST_CollectionExtract(ST_MakeValid(ST_GeomFromGeoJSON(geometry)), 3));
        DELETE FROM geo_postcode_area_staging WHERE area IS NULL OR ST_IsEmpty(area);
        CREATE INDEX geo_postcode_area_staging_area_index ON geo_postcode_area_staging USING gist (area);
        ANALYZE geo_postcode_area_staging;
    SQL;

    private const string LOCATE_ADDRESSES_SQL = <<<'SQL'
        CREATE UNLOGGED TABLE geo_address_located AS
        SELECT staging.street_name, staging.street_name_normalized, staging.house_number,
            staging.house_number_normalized, staging.postal_code, staging.city, shape_kind.from_area,
            CASE
                WHEN shape_kind.from_area THEN ST_PointOnSurface(ST_MakeValid(parsed.shape))
                ELSE parsed.shape
            END AS location
        FROM geo_address_staging AS staging
        -- OFFSET 0 keeps PostgreSQL from flattening these subqueries, which would repeat their expressions per use.
        CROSS JOIN LATERAL (SELECT ST_GeomFromGeoJSON(staging.geometry) AS shape OFFSET 0) AS parsed
        CROSS JOIN LATERAL (SELECT ST_GeometryType(parsed.shape) <> 'ST_Point' AS from_area OFFSET 0) AS shape_kind;
        DROP TABLE geo_address_staging;

        DELETE FROM geo_address_located WHERE location IS NULL OR ST_IsEmpty(location);
        UPDATE geo_address_located AS address
            SET postal_code = (
                SELECT postcode_area.postal_code
                FROM geo_postcode_area_staging AS postcode_area
                WHERE ST_Covers(postcode_area.area, address.location)
                ORDER BY postcode_area.postal_code
                LIMIT 1
            )
            WHERE address.postal_code IS NULL;
    SQL;

    private const string FILL_ADDRESSES_SQL = <<<'SQL'
        INSERT INTO geo_addresses_next
            (street_name, street_name_normalized, house_number, house_number_normalized, postal_code, city, location)
        SELECT DISTINCT ON (postal_code, street_name_normalized, house_number_normalized, cluster_number)
            street_name, street_name_normalized, house_number, house_number_normalized, postal_code, city, location
        FROM (
            SELECT street_name, street_name_normalized, house_number, house_number_normalized, postal_code, city,
                from_area, location,
                ST_ClusterDBSCAN(location, eps := :cluster_distance, minpoints := 1)
                    OVER (PARTITION BY postal_code, street_name_normalized, house_number_normalized) AS cluster_number
            FROM geo_address_located
            WHERE postal_code IS NOT NULL
        ) AS clustered
        ORDER BY postal_code, street_name_normalized, house_number_normalized, cluster_number,
            from_area DESC, ST_X(location), ST_Y(location)
    SQL;

    private const string FILL_STREETS_SQL = <<<'SQL'
        INSERT INTO geo_streets_next (street_name, street_name_normalized, postal_code, city, location)
        SELECT
            mode() WITHIN GROUP (ORDER BY street_name),
            street_name_normalized,
            postal_code,
            mode() WITHIN GROUP (ORDER BY city),
            ST_ClosestPoint(ST_Collect(location), ST_Centroid(ST_Collect(location)))
        FROM (
            SELECT street_name, street_name_normalized, postal_code, city, location,
                ST_ClusterDBSCAN(location, eps := :cluster_distance, minpoints := 1)
                    OVER (PARTITION BY postal_code, street_name_normalized) AS cluster_number
            FROM geo_addresses_next
        ) AS clustered
        GROUP BY postal_code, street_name_normalized, cluster_number
    SQL;

    private GeoSchema $schema;

    public function __construct(
        private Pgsql $connection,
        private StreetNameNormalizer $streetNameNormalizer = new StreetNameNormalizer,
        private HouseNumberParser $houseNumberParser = new HouseNumberParser,
        private float $minimumAddressRatio = 0.5,
    ) {
        $this->schema = new GeoSchema($connection);
    }

    public function load(Manifest $manifest): LoadReport
    {
        return $this->whileLocked(function () use ($manifest): LoadReport {
            $this->schema->createStateTable();
            $this->dropWorkTables();

            try {
                $report = $this->loadNextTables($manifest);
            } catch (Throwable $exception) {
                $this->dropWorkTables();

                throw $exception;
            }

            $this->dropStagingTables();

            return $report;
        });
    }

    public function activate(Manifest $manifest): void
    {
        $this->whileLocked(function () use ($manifest): void {
            if ($this->buildIdForRole('next') !== $manifest->buildId || ! $this->schema->tablesExist('_next')) {
                throw LoadRejected::notLoaded($manifest->buildId);
            }

            $this->inTransaction(function (): void {
                $this->schema->dropTables('_previous');

                if ($this->buildIdForRole('current') === null) {
                    $this->schema->dropTables('');
                } elseif ($this->schema->tablesExist('')) {
                    $this->schema->renameTables('', '_previous');
                }

                $this->schema->renameTables('_next', '');
                $this->connection->exec(<<<'SQL'
                    DELETE FROM geo_build_state WHERE role = 'previous';
                    UPDATE geo_build_state SET role = 'previous' WHERE role = 'current';
                    UPDATE geo_build_state SET role = 'current', activated_at = now() WHERE role = 'next';
                SQL);
            });
        });
    }

    public function rollback(): void
    {
        $this->whileLocked(function (): void {
            if ($this->buildIdForRole('previous') === null || ! $this->schema->tablesExist('_previous')) {
                throw new NothingToRollBack;
            }

            $this->inTransaction(function (): void {
                $this->schema->dropTables('');
                $this->schema->renameTables('_previous', '');
                $this->connection->exec(<<<'SQL'
                    DELETE FROM geo_build_state WHERE role = 'current';
                    UPDATE geo_build_state SET role = 'current', activated_at = now() WHERE role = 'previous';
                SQL);
            });
        });
    }

    public function currentBuildId(): ?string
    {
        return $this->buildIdForRole('current');
    }

    private function loadNextTables(Manifest $manifest): LoadReport
    {
        $addressesPath = $manifest->verifiedPath('addresses');
        $postcodeAreasPath = $manifest->verifiedPath('postcode_areas');

        $this->connection->exec(self::CREATE_STAGING_SQL);
        [$featuresRead, $featuresSkipped] = $this->copyFeatures(
            $addressesPath,
            'geo_address_staging',
            self::ADDRESS_COLUMNS,
            $this->addressRow(...),
        );
        $this->copyFeatures(
            $postcodeAreasPath,
            'geo_postcode_area_staging',
            self::POSTCODE_AREA_COLUMNS,
            $this->postcodeAreaRow(...),
        );

        $this->connection->exec(self::PREPARE_POSTCODE_AREAS_SQL);
        $postcodeAreasLoaded = (int) Query::scalar($this->connection, 'SELECT count(*) FROM geo_postcode_area_staging');

        if ($postcodeAreasLoaded === 0) {
            throw LoadRejected::noPostcodeAreas();
        }

        $this->connection->exec(self::LOCATE_ADDRESSES_SQL);
        $this->schema->createTables('_next');
        Query::execute($this->connection, self::FILL_ADDRESSES_SQL, [
            'cluster_distance' => self::ADDRESS_CLUSTER_DISTANCE_DEGREES,
        ]);
        $addressesLoaded = (int) Query::scalar($this->connection, 'SELECT count(*) FROM geo_addresses_next');
        $this->rejectSuspiciousCount($addressesLoaded);

        Query::execute($this->connection, self::FILL_STREETS_SQL, [
            'cluster_distance' => self::STREET_CLUSTER_DISTANCE_DEGREES,
        ]);
        $this->schema->createIndexes('_next');
        $this->connection->exec('ANALYZE geo_addresses_next; ANALYZE geo_streets_next;');
        $streetsLoaded = (int) Query::scalar($this->connection, 'SELECT count(*) FROM geo_streets_next');

        Query::execute(
            $this->connection,
            'INSERT INTO geo_build_state (role, build_id, format_version) VALUES (:role, :build_id, :format_version)',
            ['role' => 'next', 'build_id' => $manifest->buildId, 'format_version' => $manifest->formatVersion],
        );

        return new LoadReport(
            $manifest->buildId,
            $featuresRead,
            $featuresSkipped,
            $addressesLoaded,
            $streetsLoaded,
            $postcodeAreasLoaded,
        );
    }

    /**
     * @param  callable(array<mixed>): (list<string|null>|null)  $toRow
     * @return array{int, int}
     */
    private function copyFeatures(string $path, string $table, string $columns, callable $toRow): array
    {
        $handle = gzopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Cannot open {$path}.");
        }

        $read = 0;
        $skipped = 0;
        $rows = [];

        try {
            while (($line = gzgets($handle)) !== false) {
                $line = trim($line, "\x1E \t\r\n");

                if ($line === '') {
                    continue;
                }

                $read++;
                $feature = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                $row = is_array($feature) ? $toRow($feature) : null;

                if ($row === null) {
                    $skipped++;

                    continue;
                }

                $rows[] = CopyEncoder::row($row);

                if (count($rows) === self::BATCH_SIZE) {
                    $this->connection->copyFromArray($table, $rows, fields: $columns);
                    $rows = [];
                }
            }

            if ($rows !== []) {
                $this->connection->copyFromArray($table, $rows, fields: $columns);
            }
        } finally {
            gzclose($handle);
        }

        return [$read, $skipped];
    }

    /**
     * @param  array<mixed>  $feature
     * @return list<string|null>|null
     */
    private function addressRow(array $feature): ?array
    {
        $properties = $feature['properties'] ?? null;
        $geometry = $feature['geometry'] ?? null;

        if (! is_array($properties) || ! is_array($geometry)) {
            return null;
        }

        $streetName = $this->text($properties['addr:street'] ?? null) ?? $this->text($properties['addr:place'] ?? null);
        $houseNumberText = $this->text($properties['addr:housenumber'] ?? null);

        if ($streetName === null || $houseNumberText === null) {
            return null;
        }

        $streetNameNormalized = $this->streetNameNormalizer->normalize($streetName);
        $houseNumber = $this->houseNumberParser->parse($houseNumberText);

        if ($streetNameNormalized === '' || $houseNumber === null) {
            return null;
        }

        return [
            $streetName,
            $streetNameNormalized,
            $houseNumberText,
            $houseNumber->normalized(),
            $this->postalCode($properties['addr:postcode'] ?? null),
            $this->text($properties['addr:city'] ?? null),
            json_encode($geometry, JSON_THROW_ON_ERROR),
        ];
    }

    /**
     * @param  array<mixed>  $feature
     * @return list<string|null>|null
     */
    private function postcodeAreaRow(array $feature): ?array
    {
        $properties = $feature['properties'] ?? null;
        $geometry = $feature['geometry'] ?? null;
        $postalCode = is_array($properties) ? $this->postalCode($properties['postal_code'] ?? null) : null;

        if ($postalCode === null || ! is_array($geometry)) {
            return null;
        }

        return [$postalCode, json_encode($geometry, JSON_THROW_ON_ERROR)];
    }

    private function text(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function postalCode(mixed $value): ?string
    {
        $text = $this->text($value);

        return $text !== null && preg_match('/^\d{5}$/', $text) === 1 ? $text : null;
    }

    private function rejectSuspiciousCount(int $addressesLoaded): void
    {
        if ($addressesLoaded === 0) {
            throw LoadRejected::noAddresses();
        }

        if (! $this->schema->tablesExist('')) {
            return;
        }

        $liveAddresses = (int) Query::scalar($this->connection, 'SELECT count(*) FROM geo_addresses');

        if ($liveAddresses > 0 && $addressesLoaded < $liveAddresses * $this->minimumAddressRatio) {
            throw LoadRejected::tooFewAddresses($addressesLoaded, $liveAddresses, $this->minimumAddressRatio);
        }
    }

    private function dropWorkTables(): void
    {
        $this->dropStagingTables();
        $this->schema->dropTables('_next');
        Query::execute($this->connection, 'DELETE FROM geo_build_state WHERE role = :role', ['role' => 'next']);
    }

    private function dropStagingTables(): void
    {
        $this->connection->exec('DROP TABLE IF EXISTS geo_address_staging, geo_address_located, geo_postcode_area_staging');
    }

    private function buildIdForRole(string $role): ?string
    {
        if ((int) Query::scalar($this->connection, "SELECT (to_regclass('geo_build_state') IS NOT NULL)::int") !== 1) {
            return null;
        }

        $buildId = Query::scalar($this->connection, 'SELECT build_id FROM geo_build_state WHERE role = :role', ['role' => $role]);

        return is_string($buildId) ? $buildId : null;
    }

    /**
     * @param  callable(): void  $callback
     */
    private function inTransaction(callable $callback): void
    {
        $this->connection->beginTransaction();

        try {
            $callback();
            $this->connection->commit();
        } catch (Throwable $exception) {
            $this->connection->rollBack();

            throw $exception;
        }
    }

    /**
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    private function whileLocked(callable $callback): mixed
    {
        $locked = (int) Query::scalar($this->connection, 'SELECT pg_try_advisory_lock(:key)::int', ['key' => self::LOCK_KEY]);

        if ($locked !== 1) {
            throw new LoadInProgress;
        }

        try {
            return $callback();
        } finally {
            Query::execute($this->connection, 'SELECT pg_advisory_unlock(:key)', ['key' => self::LOCK_KEY]);
        }
    }
}
