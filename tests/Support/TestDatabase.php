<?php

declare(strict_types=1);

namespace DeLiCompanion\Geo\Tests\Support;

use DeLiCompanion\Geo\Loading\GeoSchema;
use PDO;
use Pdo\Pgsql;
use PHPUnit\Framework\Assert;
use RuntimeException;

final class TestDatabase
{
    public static function connect(): Pgsql
    {
        $dsn = getenv('DELI_GEO_TEST_DSN');

        if (! is_string($dsn) || $dsn === '') {
            Assert::markTestSkipped('Set DELI_GEO_TEST_DSN to run the database tests (docker compose run --rm php composer test).');
        }

        $connection = PDO::connect($dsn);

        if (! $connection instanceof Pgsql) {
            throw new RuntimeException('DELI_GEO_TEST_DSN must point to PostgreSQL.');
        }

        return $connection;
    }

    public static function fresh(): Pgsql
    {
        $connection = self::connect();
        $connection->exec(<<<'SQL'
            DROP TABLE IF EXISTS
                geo_addresses, geo_streets,
                geo_addresses_next, geo_streets_next,
                geo_addresses_previous, geo_streets_previous,
                geo_build_state, geo_address_staging, geo_address_located, geo_postcode_area_staging
        SQL);
        (new GeoSchema($connection))->createExtensions();

        return $connection;
    }

    public static function count(Pgsql $connection, string $table): int
    {
        return (int) $connection->query("SELECT count(*) FROM {$table}")->fetchColumn();
    }

    public static function exists(Pgsql $connection, string $table): bool
    {
        return (int) $connection->query("SELECT (to_regclass('{$table}') IS NOT NULL)::int")->fetchColumn() === 1;
    }

    /**
     * @return list<string>
     */
    public static function indexNames(Pgsql $connection, string $table): array
    {
        $names = $connection->query("SELECT indexname FROM pg_indexes WHERE tablename = '{$table}' ORDER BY indexname")
            ->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_map(strval(...), $names));
    }
}
